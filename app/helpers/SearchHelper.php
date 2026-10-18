<?php
// FILE: /app/helpers/SearchHelper.php

/**
 * SplashEstate CRM - Search Helper
 * Builds dynamic, injection-safe WHERE / ORDER BY / LIMIT clauses.
 *
 * Safety rules enforced here:
 *   - every column name must look like an identifier ("col" or "alias.col");
 *     anything else throws InvalidArgumentException
 *   - operators are whitelisted
 *   - every value is bound; each placeholder name is generated once (native
 *     prepares do not allow re-using a named placeholder)
 *   - ORDER BY only accepts a column from an explicit whitelist
 *   - LIMIT/OFFSET are cast to integers
 *
 * Typical use:
 *   $s = new SearchHelper();
 *   $s->where('l.tenant_id', $tenantId)
 *     ->applyFilters($filters, SearchHelper::leadFilterDefinition())
 *     ->orderBy($filters['sort'], $filters['direction'], array_keys(SearchHelper::leadSortOptions()), 'l.created_at')
 *     ->limit($perPage, $offset);
 *   $rows = $s->execute("SELECT l.* FROM leads l");
 */

class SearchHelper {

    private $db;
    private $conditions = array();
    private $params = array();
    private $orderBy = '';
    private $limit = '';
    private $paramCounter = 0;

    private static $operators = array('=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN');

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Whether a string is a safe column reference ("col" or "alias.col")
     * @param string $field
     * @return bool
     */
    public static function isValidColumn($field) {
        return is_string($field) && preg_match('/^([A-Za-z_][A-Za-z0-9_]{0,63}\.)?[A-Za-z_][A-Za-z0-9_]{0,63}$/', $field) === 1;
    }

    /**
     * Throw on an unsafe column reference
     * @param string $field
     * @return string The field (for chaining)
     */
    private static function assertColumn($field) {
        if (!self::isValidColumn($field)) {
            throw new InvalidArgumentException('Invalid column name');
        }
        return $field;
    }

    /**
     * Generate a fresh, unique placeholder and bind its value
     * @param mixed $value
     * @return string Placeholder name
     */
    private function bind($value) {
        $name = ':sh_p' . $this->paramCounter++;
        $this->params[$name] = $value;
        return $name;
    }

    /**
     * Escape LIKE wildcards in user input
     * @param string $value
     * @return string
     */
    public static function escapeLike($value) {
        return addcslashes((string)$value, '\\%_');
    }

    /**
     * Add a WHERE condition
     * @param string $field Column name ("col" or "alias.col")
     * @param mixed $value Value (empty string / null are ignored)
     * @param string $operator =, !=, <>, >, <, >=, <=, LIKE, NOT LIKE, IN, NOT IN
     * @return SearchHelper
     */
    public function where($field, $value, $operator = '=') {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return $this;
        }
        self::assertColumn($field);
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, self::$operators, true)) {
            throw new InvalidArgumentException('Invalid operator');
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            $values = is_array($value) ? $value : array($value);
            $placeholders = array();
            foreach ($values as $v) {
                if (is_scalar($v)) {
                    $placeholders[] = $this->bind((string)$v);
                }
            }
            if (!empty($placeholders)) {
                $this->conditions[] = "{$field} {$operator} (" . implode(', ', $placeholders) . ")";
            }
            return $this;
        }

        if (!is_scalar($value)) {
            return $this;
        }

        if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
            $this->conditions[] = "{$field} {$operator} " . $this->bind('%' . self::escapeLike($value) . '%');
        } else {
            $this->conditions[] = "{$field} {$operator} " . $this->bind($value);
        }

        return $this;
    }

    /**
     * Add a date range filter (inclusive). Invalid dates are ignored.
     * @param string $field Date/datetime column
     * @param string $startDate Y-m-d
     * @param string $endDate Y-m-d
     * @return SearchHelper
     */
    public function dateRange($field, $startDate, $endDate) {
        self::assertColumn($field);
        $start = self::validDate($startDate);
        $end = self::validDate($endDate);

        if ($start !== null) {
            $this->conditions[] = "{$field} >= " . $this->bind($start . ' 00:00:00');
        }
        if ($end !== null) {
            $this->conditions[] = "{$field} <= " . $this->bind($end . ' 23:59:59');
        }
        return $this;
    }

    /**
     * Add a numeric range filter (inclusive). Non-numeric bounds are ignored.
     * @param string $field Numeric column
     * @param mixed $min
     * @param mixed $max
     * @return SearchHelper
     */
    public function numericRange($field, $min, $max) {
        self::assertColumn($field);
        if ($min !== null && $min !== '' && is_numeric($min)) {
            $this->conditions[] = "{$field} >= " . $this->bind((float)$min);
        }
        if ($max !== null && $max !== '' && is_numeric($max)) {
            $this->conditions[] = "{$field} <= " . $this->bind((float)$max);
        }
        return $this;
    }

    /**
     * Free-text search across several columns (OR'd together, LIKE %term%)
     * @param array $fields Column names
     * @param string $query Search term
     * @return SearchHelper
     */
    public function search($fields, $query) {
        if (!is_scalar($query) || trim((string)$query) === '' || empty($fields)) {
            return $this;
        }
        $term = '%' . self::escapeLike(trim((string)$query)) . '%';

        $parts = array();
        foreach ($fields as $field) {
            self::assertColumn($field);
            // A fresh placeholder per column: native prepares cannot reuse one
            $parts[] = "{$field} LIKE " . $this->bind($term);
        }
        $this->conditions[] = '(' . implode(' OR ', $parts) . ')';
        return $this;
    }

    /**
     * Add an OR group of equality conditions: (a = x OR b = y)
     * @param array $orConditions column => value
     * @return SearchHelper
     */
    public function orWhere($orConditions) {
        if (empty($orConditions) || !is_array($orConditions)) {
            return $this;
        }
        $parts = array();
        foreach ($orConditions as $field => $value) {
            self::assertColumn($field);
            if (is_scalar($value)) {
                $parts[] = "{$field} = " . $this->bind($value);
            }
        }
        if (!empty($parts)) {
            $this->conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        return $this;
    }

    /**
     * Apply request filters according to a definition (see leadFilterDefinition()).
     * Only keys present in the definition are used; values are validated per type.
     *
     * Definition entry types:
     *   'equals'  => array('type' => 'equals', 'column' => 'l.source')
     *   'int'     => array('type' => 'int', 'column' => 'l.assigned_to')
     *   'enum'    => array('type' => 'enum', 'column' => 'l.status', 'values' => array(...))
     *   'search'  => array('type' => 'search', 'columns' => array(...))
     *   'range'   => array('type' => 'range', 'column' => 'l.budget_min', 'min' => 'budget_min', 'max' => 'budget_max')
     *   'daterange' => array('type' => 'daterange', 'column' => 'l.created_at', 'from' => 'created_from', 'to' => 'created_to')
     *
     * @param array $filters Raw filter values (e.g. $_GET)
     * @param array $definition Filter definition
     * @return SearchHelper
     */
    public function applyFilters($filters, $definition) {
        if (!is_array($filters)) {
            return $this;
        }
        foreach ($definition as $key => $def) {
            $type = $def['type'];
            if ($type === 'range') {
                $this->numericRange(
                    $def['column'],
                    isset($filters[$def['min']]) && is_scalar($filters[$def['min']]) ? $filters[$def['min']] : null,
                    isset($filters[$def['max']]) && is_scalar($filters[$def['max']]) ? $filters[$def['max']] : null
                );
                continue;
            }
            if ($type === 'daterange') {
                $this->dateRange(
                    $def['column'],
                    isset($filters[$def['from']]) && is_scalar($filters[$def['from']]) ? $filters[$def['from']] : null,
                    isset($filters[$def['to']]) && is_scalar($filters[$def['to']]) ? $filters[$def['to']] : null
                );
                continue;
            }

            if (!isset($filters[$key]) || !is_scalar($filters[$key]) || trim((string)$filters[$key]) === '') {
                continue;
            }
            $value = trim((string)$filters[$key]);

            switch ($type) {
                case 'int':
                    if (ctype_digit($value)) {
                        $this->where($def['column'], (int)$value);
                    }
                    break;
                case 'enum':
                    if (in_array($value, $def['values'], true)) {
                        $this->where($def['column'], $value);
                    }
                    break;
                case 'search':
                    $this->search($def['columns'], $value);
                    break;
                case 'equals':
                default:
                    $this->where($def['column'], $value);
                    break;
            }
        }
        return $this;
    }

    /**
     * Set ORDER BY from user input, restricted to a whitelist of columns.
     * @param string $field Requested column
     * @param string $direction ASC or DESC
     * @param array|null $allowed Allowed columns (null = $field must be a valid identifier; prefer a whitelist)
     * @param string|null $default Column used when $field is not allowed
     * @return SearchHelper
     */
    public function orderBy($field, $direction = 'ASC', $allowed = null, $default = null) {
        $direction = (is_string($direction) && strtoupper($direction) === 'DESC') ? 'DESC' : 'ASC';

        if (is_array($allowed)) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                $field = $default;
            }
        }
        if ($field === null || !self::isValidColumn($field)) {
            if ($default !== null && self::isValidColumn($default)) {
                $field = $default;
            } else {
                $this->orderBy = '';
                return $this;
            }
        }

        $this->orderBy = "ORDER BY {$field} {$direction}";
        return $this;
    }

    /**
     * Set LIMIT and OFFSET
     * @param int $limit
     * @param int $offset
     * @return SearchHelper
     */
    public function limit($limit, $offset = 0) {
        $limit = max(1, (int)$limit);
        $offset = max(0, (int)$offset);
        $this->limit = "LIMIT {$limit} OFFSET {$offset}";
        return $this;
    }

    /**
     * WHERE clause without the WHERE keyword
     * @return string
     */
    public function getWhereClause() {
        return empty($this->conditions) ? '' : implode(' AND ', $this->conditions);
    }

    /**
     * Bound parameters (placeholder => value)
     * @return array
     */
    public function getParams() {
        return $this->params;
    }

    /**
     * Build the full SQL
     * @param string $baseQuery e.g. "SELECT * FROM leads l"
     * @param string $additionalWhere Trusted, developer-written SQL only (never user input).
     *        Prefer where('l.tenant_id', $tenantId) which binds the value.
     * @return string
     */
    public function buildQuery($baseQuery, $additionalWhere = '') {
        $query = $baseQuery;

        $whereParts = array();
        if (!empty($additionalWhere)) {
            $whereParts[] = '(' . $additionalWhere . ')';
        }
        if (!empty($this->conditions)) {
            $whereParts[] = $this->getWhereClause();
        }
        if (!empty($whereParts)) {
            $query .= ' WHERE ' . implode(' AND ', $whereParts);
        }
        if ($this->orderBy !== '') {
            $query .= ' ' . $this->orderBy;
        }
        if ($this->limit !== '') {
            $query .= ' ' . $this->limit;
        }
        return $query;
    }

    /**
     * Execute and return all rows
     * @param string $baseQuery
     * @param string $additionalWhere Trusted SQL only
     * @return array
     */
    public function execute($baseQuery, $additionalWhere = '') {
        $query = $this->buildQuery($baseQuery, $additionalWhere);
        try {
            $stmt = $this->db->prepare($query);
            $this->bindAll($stmt);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Search query error: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * COUNT(*) with the current conditions (ORDER BY / LIMIT are ignored)
     * @param string $table Table name, optionally with alias ("leads l")
     * @param string $additionalWhere Trusted SQL only
     * @return int
     */
    public function count($table, $additionalWhere = '') {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*( [A-Za-z_][A-Za-z0-9_]*)?$/', (string)$table)) {
            throw new InvalidArgumentException('Invalid table name');
        }
        $orderBy = $this->orderBy;
        $limit = $this->limit;
        $this->orderBy = '';
        $this->limit = '';
        $query = $this->buildQuery("SELECT COUNT(*) AS total FROM {$table}", $additionalWhere);
        $this->orderBy = $orderBy;
        $this->limit = $limit;

        try {
            $stmt = $this->db->prepare($query);
            $this->bindAll($stmt);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['total'];
        } catch (PDOException $e) {
            error_log('Count query error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Reset all state
     * @return SearchHelper
     */
    public function reset() {
        $this->conditions = array();
        $this->params = array();
        $this->orderBy = '';
        $this->limit = '';
        $this->paramCounter = 0;
        return $this;
    }

    /**
     * Bind every parameter with an appropriate PDO type
     * @param PDOStatement $stmt
     */
    private function bindAll($stmt) {
        foreach ($this->params as $name => $value) {
            if (is_int($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($name, $value === null ? null : (string)$value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }
        }
    }

    /**
     * Return a Y-m-d date string if valid, otherwise null
     * @param mixed $date
     * @return string|null
     */
    public static function validDate($date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $parts = explode('-', $date);
        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]) ? $date : null;
    }

    // ------------------------------------------------------------------
    // Module definitions shared by the leads list, exports and saved searches
    // ------------------------------------------------------------------

    /**
     * Lead filter definition (keys match the leads index query string)
     * @return array
     */
    public static function leadFilterDefinition() {
        return array(
            'status' => array('type' => 'enum', 'column' => 'l.status',
                'values' => array('new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost')),
            'assigned_to' => array('type' => 'int', 'column' => 'l.assigned_to'),
            'source' => array('type' => 'equals', 'column' => 'l.source'),
            'interest_type' => array('type' => 'enum', 'column' => 'l.interest_type',
                'values' => array('buy', 'sell', 'rent', 'lease')),
            'search' => array('type' => 'search',
                'columns' => array('l.first_name', 'l.last_name', 'l.email', 'l.phone', 'l.notes')),
            'budget' => array('type' => 'range', 'column' => 'l.budget_min', 'min' => 'budget_min', 'max' => 'budget_max'),
            'created' => array('type' => 'daterange', 'column' => 'l.created_at', 'from' => 'created_from', 'to' => 'created_to')
        );
    }

    /**
     * Allowed lead sort columns => label
     * @return array
     */
    public static function leadSortOptions() {
        return array(
            'l.created_at' => 'Created Date',
            'l.updated_at' => 'Last Updated',
            'l.first_name' => 'First Name',
            'l.last_name' => 'Last Name',
            'l.status' => 'Status',
            'l.source' => 'Source',
            'l.budget_min' => 'Budget'
        );
    }

    /**
     * Query-string keys accepted for a module's saved searches / filters
     * @param string $module
     * @return array
     */
    public static function allowedFilterKeys($module) {
        $common = array('search', 'sort', 'direction', 'status', 'assigned_to', 'created_from', 'created_to');
        switch ($module) {
            case 'leads':
                return array_merge($common, array('source', 'interest_type', 'budget_min', 'budget_max'));
            case 'clients':
                return array_merge($common, array('client_type', 'city'));
            case 'properties':
                return array_merge($common, array('property_type', 'listing_type', 'city', 'price_min', 'price_max', 'bedrooms'));
            case 'deals':
                return array_merge($common, array('stage', 'value_min', 'value_max'));
            case 'tasks':
                return array_merge($common, array('priority', 'related_to_type', 'due_from', 'due_to'));
            default:
                return $common;
        }
    }
}

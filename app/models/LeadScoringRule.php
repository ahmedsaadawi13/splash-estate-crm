<?php
/**
 * Lead Scoring Rule Model
 *
 * Manages scoring rules for lead qualification. Rules are tenant-scoped:
 * use the inherited getById($id, $tenantId) / update($id, $tenantId, $data) /
 * delete($id, $tenantId) for single-rule access.
 */

require_once APP_PATH . '/core/Model.php';

class LeadScoringRule extends Model
{
    protected $table = 'lead_scoring_rules';

    /** Lead columns whose value is numeric (compared as numbers) */
    private static $numericFields = array('budget_min', 'budget_max', 'assigned_to');

    /**
     * Get all scoring rules for a tenant
     * @param int $tenantId Tenant ID
     * @param string $status 'active', 'inactive' or 'all'
     * @return array
     */
    public function getByTenant($tenantId, $status = 'active')
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = :tenant_id";
        if ($status !== 'all') {
            $sql .= " AND status = :status";
        }
        $sql .= " ORDER BY execution_order ASC, id ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        if ($status !== 'all') {
            $stmt->bindValue(':status', $status);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get rules by type
     * @param int $tenantId Tenant ID
     * @param string $ruleType Rule type
     * @param string $status 'active', 'inactive' or 'all'
     * @return array
     */
    public function getByType($tenantId, $ruleType, $status = 'active')
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = :tenant_id AND rule_type = :rule_type";
        if ($status !== 'all') {
            $sql .= " AND status = :status";
        }
        $sql .= " ORDER BY execution_order ASC, id ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':rule_type', $ruleType);
        if ($status !== 'all') {
            $stmt->bindValue(':status', $status);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * All rules of a tenant grouped by rule type (every type key present)
     * @param int $tenantId Tenant ID
     * @return array rule_type => rules
     */
    public function getGroupedByType($tenantId)
    {
        $grouped = array();
        foreach (array_keys(self::getRuleTypes()) as $type) {
            $grouped[$type] = array();
        }
        foreach ($this->getByTenant($tenantId, 'all') as $rule) {
            $grouped[$rule['rule_type']][] = $rule;
        }
        return $grouped;
    }

    /**
     * Evaluate a rule against lead data
     * @param array $rule Rule row
     * @param array $leadData Lead row
     * @return bool
     */
    public function evaluate($rule, $leadData)
    {
        $fieldName = isset($rule['field_name']) ? $rule['field_name'] : '';
        $operator = isset($rule['operator']) ? $rule['operator'] : '';
        $expected = isset($rule['field_value']) ? trim((string)$rule['field_value']) : '';

        $raw = isset($leadData[$fieldName]) ? $leadData[$fieldName] : null;
        $actual = $raw === null ? '' : trim((string)$raw);
        $isNumericField = in_array($fieldName, self::$numericFields, true);

        switch ($operator) {
            case 'equals':
                return $this->valuesEqual($actual, $expected);

            case 'not_equals':
                return !$this->valuesEqual($actual, $expected);

            case 'contains':
                return $actual !== '' && $expected !== '' && stripos($actual, $expected) !== false;

            case 'not_contains':
                return $expected === '' || $actual === '' || stripos($actual, $expected) === false;

            case 'greater_than':
            case 'less_than':
            case 'greater_than_or_equal':
            case 'less_than_or_equal':
                $a = self::toNumber($actual);
                $b = self::toNumber($expected);
                if ($a === null || $b === null) {
                    return false;
                }
                if ($operator === 'greater_than') {
                    return $a > $b;
                }
                if ($operator === 'less_than') {
                    return $a < $b;
                }
                if ($operator === 'greater_than_or_equal') {
                    return $a >= $b;
                }
                return $a <= $b;

            case 'is_empty':
                return $this->isEmptyValue($actual, $isNumericField);

            case 'is_not_empty':
                return !$this->isEmptyValue($actual, $isNumericField);

            case 'in':
                return $this->inList($fieldName, $actual, $expected);

            case 'not_in':
                return !$this->inList($fieldName, $actual, $expected);

            default:
                return false;
        }
    }

    /**
     * Case-insensitive / numeric-aware equality
     */
    private function valuesEqual($actual, $expected)
    {
        $a = self::toNumber($actual);
        $b = self::toNumber($expected);
        if ($a !== null && $b !== null) {
            return abs($a - $b) < 0.000001;
        }
        return strcasecmp($actual, $expected) === 0;
    }

    /**
     * Empty check: blank strings, and 0 for numeric columns
     */
    private function isEmptyValue($actual, $isNumericField)
    {
        if ($actual === '') {
            return true;
        }
        if ($isNumericField) {
            $n = self::toNumber($actual);
            return $n === null || $n == 0;
        }
        return false;
    }

    /**
     * Membership test for in / not_in. Values are trimmed and compared
     * case-insensitively; for the comma-separated `tags` column the rule
     * matches when any of the lead's tags is in the list.
     */
    private function inList($fieldName, $actual, $expected)
    {
        $list = self::splitList($expected);
        if (empty($list) || $actual === '') {
            return false;
        }
        $candidates = $fieldName === 'tags' ? self::splitList($actual) : array(strtolower($actual));
        return count(array_intersect($candidates, $list)) > 0;
    }

    /**
     * Split a comma-separated list into trimmed lower-case non-empty values
     * @param string $value
     * @return array
     */
    private static function splitList($value)
    {
        $out = array();
        foreach (explode(',', (string)$value) as $item) {
            $item = strtolower(trim($item));
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Parse a number, tolerating currency symbols, spaces and thousands separators
     * ("$500,000" -> 500000). Returns null when not numeric.
     * @param mixed $value
     * @return float|null
     */
    public static function toNumber($value)
    {
        if ($value === null) {
            return null;
        }
        $clean = str_replace(array(',', ' ', '$'), '', trim((string)$value));
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        return (float)$clean;
    }

    /**
     * Validate rule input. Returns an array of field => error.
     * @param array $data name, rule_type, field_name, operator, field_value, points, execution_order
     * @return array
     */
    public static function validate($data)
    {
        $errors = array();
        $name = isset($data['name']) ? trim((string)$data['name']) : '';
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (strlen($name) > 255) {
            $errors['name'] = 'Name must be at most 255 characters.';
        }
        if (!isset($data['rule_type']) || !array_key_exists($data['rule_type'], self::getRuleTypes())) {
            $errors['rule_type'] = 'Choose a valid rule type.';
        }
        if (!isset($data['field_name']) || !array_key_exists($data['field_name'], self::getAllowedFields())) {
            $errors['field_name'] = 'Choose a valid lead field.';
        }
        $operator = isset($data['operator']) ? $data['operator'] : '';
        if (!array_key_exists($operator, self::getOperators())) {
            $errors['operator'] = 'Choose a valid operator.';
        }
        $value = isset($data['field_value']) ? trim((string)$data['field_value']) : '';
        if (!in_array($operator, array('is_empty', 'is_not_empty'), true) && $value === '' && !isset($errors['operator'])) {
            $errors['field_value'] = 'A comparison value is required for this operator.';
        }
        if (in_array($operator, array('greater_than', 'less_than', 'greater_than_or_equal', 'less_than_or_equal'), true)
            && $value !== '' && self::toNumber($value) === null) {
            $errors['field_value'] = 'This operator needs a numeric value.';
        }
        if (strlen($value) > 255) {
            $errors['field_value'] = 'Value must be at most 255 characters.';
        }
        if (!isset($data['points']) || filter_var($data['points'], FILTER_VALIDATE_INT) === false) {
            $errors['points'] = 'Points must be a whole number.';
        } elseif ((int)$data['points'] < -100 || (int)$data['points'] > 100) {
            $errors['points'] = 'Points must be between -100 and 100.';
        }
        if (isset($data['execution_order']) && $data['execution_order'] !== ''
            && filter_var($data['execution_order'], FILTER_VALIDATE_INT) === false) {
            $errors['execution_order'] = 'Order must be a whole number.';
        }
        return $errors;
    }

    /**
     * Get statistics about rules
     * @param int $tenantId Tenant ID
     * @return array
     */
    public function getStatistics($tenantId)
    {
        $sql = "SELECT
                    COUNT(*) as total_rules,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_rules,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_rules,
                    SUM(CASE WHEN rule_type = 'demographic' THEN 1 ELSE 0 END) as demographic_rules,
                    SUM(CASE WHEN rule_type = 'behavioral' THEN 1 ELSE 0 END) as behavioral_rules,
                    SUM(CASE WHEN rule_type = 'engagement' THEN 1 ELSE 0 END) as engagement_rules,
                    SUM(CASE WHEN rule_type = 'custom' THEN 1 ELSE 0 END) as custom_rules,
                    SUM(CASE WHEN status = 'active' AND points > 0 THEN points ELSE 0 END) as max_positive_points
                FROM {$this->table}
                WHERE tenant_id = :tenant_id";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array(':tenant_id' => (int)$tenantId));
        $stats = $stmt->fetch();

        $out = array();
        foreach ((array)$stats as $key => $value) {
            $out[$key] = (int)$value;
        }
        return $out;
    }

    /**
     * Set execution order of a tenant's rule
     * @param int $ruleId Rule ID
     * @param int $tenantId Tenant ID
     * @param int $newOrder New order
     * @return bool
     */
    public function reorder($ruleId, $tenantId, $newOrder)
    {
        return $this->update($ruleId, $tenantId, array('execution_order' => (int)$newOrder));
    }

    /**
     * Toggle a tenant's rule between active and inactive
     * @param int $ruleId Rule ID
     * @param int $tenantId Tenant ID
     * @return string|false New status, or false when not found
     */
    public function toggleStatus($ruleId, $tenantId)
    {
        $rule = $this->getById($ruleId, $tenantId);
        if (!$rule) {
            return false;
        }
        $newStatus = $rule['status'] === 'active' ? 'inactive' : 'active';
        return $this->update($ruleId, $tenantId, array('status' => $newStatus)) ? $newStatus : false;
    }

    /**
     * Lead columns a rule may evaluate (whitelist) => label
     * @return array
     */
    public static function getAllowedFields()
    {
        return array(
            'source' => 'Source',
            'status' => 'Status',
            'interest_type' => 'Interest type',
            'budget_min' => 'Budget (min)',
            'budget_max' => 'Budget (max)',
            'email' => 'Email',
            'phone' => 'Phone',
            'tags' => 'Tags',
            'assigned_to' => 'Assigned agent (user ID)',
            'notes' => 'Notes'
        );
    }

    /**
     * Get available rule types
     * @return array
     */
    public static function getRuleTypes()
    {
        return array(
            'demographic' => array(
                'name' => 'Demographic',
                'description' => 'Based on lead characteristics (budget, location, property type)'
            ),
            'behavioral' => array(
                'name' => 'Behavioral',
                'description' => 'Based on lead actions (property views, contact frequency)'
            ),
            'engagement' => array(
                'name' => 'Engagement',
                'description' => 'Based on lead interactions (email opens, responses)'
            ),
            'custom' => array(
                'name' => 'Custom',
                'description' => 'Custom scoring criteria'
            )
        );
    }

    /**
     * Get available operators
     * @return array
     */
    public static function getOperators()
    {
        return array(
            'equals' => 'Equals',
            'not_equals' => 'Not Equals',
            'contains' => 'Contains',
            'not_contains' => 'Does Not Contain',
            'greater_than' => 'Greater Than',
            'less_than' => 'Less Than',
            'greater_than_or_equal' => 'Greater Than or Equal',
            'less_than_or_equal' => 'Less Than or Equal',
            'is_empty' => 'Is Empty',
            'is_not_empty' => 'Is Not Empty',
            'in' => 'In List (comma-separated)',
            'not_in' => 'Not In List'
        );
    }
}

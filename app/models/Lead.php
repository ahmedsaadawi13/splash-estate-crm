<?php
// FILE: /app/models/Lead.php

/**
 * SplashEstate CRM - Lead Model
 * Handles lead/prospect management
 */

class Lead extends Model {

    protected $table = 'leads';

    /** Allowed values for leads.status */
    const STATUSES = array('new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost');

    /** Allowed values for leads.interest_type */
    const INTEREST_TYPES = array('buy', 'sell', 'rent', 'lease');

    /** Sortable columns for list views (user input => SQL expression) */
    private static $sortColumns = array(
        'l.created_at' => 'l.created_at',
        'l.first_name' => 'l.first_name',
        'l.last_name' => 'l.last_name',
        'l.status' => 'l.status',
        'l.budget_min' => 'l.budget_min',
        'score' => 'ls.score',
        'created_at' => 'l.created_at',
        'first_name' => 'l.first_name',
        'last_name' => 'l.last_name',
        'status' => 'l.status',
        'budget_min' => 'l.budget_min'
    );

    /**
     * Base SELECT used by list and detail queries (assigned user + lead score)
     * @return string
     */
    private function baseSelect() {
        return "SELECT l.*,
                CONCAT(u.first_name, ' ', u.last_name) AS assigned_to_name,
                u.email AS assigned_to_email,
                ls.score AS score,
                ls.grade AS grade
                FROM {$this->table} l
                LEFT JOIN users u ON l.assigned_to = u.id AND u.tenant_id = l.tenant_id
                LEFT JOIN lead_scores ls ON ls.lead_id = l.id";
    }

    /**
     * Build the WHERE clause for list/count queries.
     * Supported filters: status, assigned_to, source, interest_type, grade, tag,
     * search, budget_min, budget_max, created_from, created_to.
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @param array $params (out) bound parameters
     * @return string SQL starting with " WHERE "
     */
    private function buildWhere($tenantId, $filters, &$params) {
        $where = array('l.tenant_id = :tenant_id');
        $params = array(':tenant_id' => (int)$tenantId);

        if (!empty($filters['status'])) {
            $where[] = 'l.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = 'l.assigned_to = :assigned_to';
            $params[':assigned_to'] = (int)$filters['assigned_to'];
        }
        if (!empty($filters['source'])) {
            $where[] = 'l.source = :source';
            $params[':source'] = $filters['source'];
        }
        if (!empty($filters['interest_type'])) {
            $where[] = 'l.interest_type = :interest_type';
            $params[':interest_type'] = $filters['interest_type'];
        }
        if (!empty($filters['grade'])) {
            $where[] = 'ls.grade = :grade';
            $params[':grade'] = $filters['grade'];
        }
        if (!empty($filters['tag'])) {
            $where[] = "FIND_IN_SET(:tag, REPLACE(l.tags, ', ', ',')) > 0";
            $params[':tag'] = trim($filters['tag']);
        }
        if (!empty($filters['search'])) {
            // Native prepares: each placeholder may only be used once
            $term = '%' . $filters['search'] . '%';
            $where[] = '(l.first_name LIKE :s1 OR l.last_name LIKE :s2 OR l.email LIKE :s3 OR l.phone LIKE :s4'
                     . " OR CONCAT(l.first_name, ' ', IFNULL(l.last_name, '')) LIKE :s5 OR l.notes LIKE :s6 OR l.tags LIKE :s7)";
            for ($i = 1; $i <= 7; $i++) {
                $params[':s' . $i] = $term;
            }
        }
        // Budget range: leads whose budget overlaps the requested range
        if (isset($filters['budget_min']) && is_numeric($filters['budget_min'])) {
            $where[] = 'COALESCE(l.budget_max, l.budget_min) >= :budget_min';
            $params[':budget_min'] = (float)$filters['budget_min'];
        }
        if (isset($filters['budget_max']) && is_numeric($filters['budget_max'])) {
            $where[] = 'COALESCE(l.budget_min, l.budget_max) <= :budget_max';
            $params[':budget_max'] = (float)$filters['budget_max'];
        }
        if (!empty($filters['created_from']) && self::isDate($filters['created_from'])) {
            $where[] = 'l.created_at >= :created_from';
            $params[':created_from'] = $filters['created_from'] . ' 00:00:00';
        }
        if (!empty($filters['created_to']) && self::isDate($filters['created_to'])) {
            $where[] = 'l.created_at <= :created_to';
            $params[':created_to'] = $filters['created_to'] . ' 23:59:59';
        }

        return ' WHERE ' . implode(' AND ', $where);
    }

    /**
     * Whether a string is a Y-m-d date
     * @param string $value
     * @return bool
     */
    private static function isDate($value) {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * Get leads with assigned user info and lead score
     * @param int $tenantId Tenant ID
     * @param int $page Page number
     * @param int $perPage Records per page
     * @param array $filters Filters (see buildWhere) plus sort/direction
     * @return array Leads with user info
     */
    public function getLeadsWithUser($tenantId, $page = 1, $perPage = 20, $filters = array()) {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $params = array();
        $sql = $this->baseSelect() . $this->buildWhere($tenantId, $filters, $params);

        $sortKey = isset($filters['sort']) ? $filters['sort'] : '';
        $sortColumn = isset(self::$sortColumns[$sortKey]) ? self::$sortColumns[$sortKey] : 'l.created_at';
        $direction = (isset($filters['direction']) && strtoupper($filters['direction']) === 'ASC') ? 'ASC' : 'DESC';
        $sql .= " ORDER BY {$sortColumn} {$direction}, l.id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count leads matching the same filters as getLeadsWithUser()
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @return int
     */
    public function countLeads($tenantId, $filters = array()) {
        $params = array();
        $sql = "SELECT COUNT(*) FROM {$this->table} l
                LEFT JOIN lead_scores ls ON ls.lead_id = l.id"
             . $this->buildWhere($tenantId, $filters, $params);
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count leads (overrides Model::count so 'search', 'tag', ranges etc. work)
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @return int
     */
    public function count($tenantId, $filters = array()) {
        return $this->countLeads($tenantId, $filters);
    }

    /**
     * Advanced search (kept for backwards compatibility; same as getLeadsWithUser)
     */
    public function advancedSearch($tenantId, $filters = array(), $page = 1, $perPage = 20) {
        return $this->getLeadsWithUser($tenantId, $page, $perPage, $filters);
    }

    /**
     * Advanced search count (kept for backwards compatibility; same as countLeads)
     */
    public function advancedSearchCount($tenantId, $filters = array()) {
        return $this->countLeads($tenantId, $filters);
    }

    /**
     * Get lead by ID with user info and score
     * @param int $id Lead ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function getLeadWithUser($id, $tenantId) {
        $sql = $this->baseSelect() . " WHERE l.id = :id AND l.tenant_id = :tenant_id LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Create lead
     * @param array $data Lead data (tenant_id and first_name required)
     * @return int|false Lead ID or false
     */
    public function createLead($data) {
        $sql = "INSERT INTO {$this->table}
                (tenant_id, assigned_to, first_name, last_name, email, phone, source, status, interest_type, budget_min, budget_max, notes, tags, created_at)
                VALUES (:tenant_id, :assigned_to, :first_name, :last_name, :email, :phone, :source, :status, :interest_type, :budget_min, :budget_max, :notes, :tags, NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$data['tenant_id'], PDO::PARAM_INT);
        $assigned = isset($data['assigned_to']) && $data['assigned_to'] !== '' ? (int)$data['assigned_to'] : null;
        $stmt->bindValue(':assigned_to', $assigned, $assigned === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':first_name', $data['first_name']);
        $stmt->bindValue(':last_name', self::nullIfEmpty($data, 'last_name'));
        $stmt->bindValue(':email', self::nullIfEmpty($data, 'email'));
        $stmt->bindValue(':phone', self::nullIfEmpty($data, 'phone'));
        $source = self::nullIfEmpty($data, 'source');
        $stmt->bindValue(':source', $source !== null ? $source : 'Website');
        $stmt->bindValue(':status', !empty($data['status']) ? $data['status'] : 'new');
        $stmt->bindValue(':interest_type', self::nullIfEmpty($data, 'interest_type'));
        $stmt->bindValue(':budget_min', self::nullIfEmpty($data, 'budget_min'));
        $stmt->bindValue(':budget_max', self::nullIfEmpty($data, 'budget_max'));
        $stmt->bindValue(':notes', self::nullIfEmpty($data, 'notes'));
        $stmt->bindValue(':tags', self::nullIfEmpty($data, 'tags'));

        if ($stmt->execute()) {
            return (int)$this->conn->lastInsertId();
        }
        return false;
    }

    /**
     * Value of $data[$key], or null when missing/empty string
     */
    private static function nullIfEmpty($data, $key) {
        if (!isset($data[$key]) || $data[$key] === '') {
            return null;
        }
        return $data[$key];
    }

    /**
     * Update lead status
     * @param int $id Lead ID
     * @param int $tenantId Tenant ID
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus($id, $tenantId, $status) {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $sql = "UPDATE {$this->table} SET status = :status, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Client created from a lead, if the lead was already converted
     * @param int $leadId Lead ID
     * @param int $tenantId Tenant ID
     * @return array|false Client row
     */
    public function getConvertedClient($leadId, $tenantId) {
        $stmt = $this->conn->prepare("SELECT * FROM clients WHERE lead_id = :lead_id AND tenant_id = :tenant_id ORDER BY id ASC LIMIT 1");
        $stmt->bindValue(':lead_id', (int)$leadId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Convert lead to client (idempotent: returns the existing client if already converted).
     * Marks the lead as won. Callers are responsible for dispatching events.
     * @param int $id Lead ID
     * @param int $tenantId Tenant ID
     * @return int|false Client ID or false
     */
    public function convertToClient($id, $tenantId) {
        $lead = $this->getById($id, $tenantId);
        if (!$lead) {
            return false;
        }

        $existing = $this->getConvertedClient($id, $tenantId);
        if ($existing) {
            return (int)$existing['id'];
        }

        require_once APP_PATH . '/models/Client.php';
        $clientModel = new Client();

        $typeMap = array('buy' => 'buyer', 'sell' => 'seller', 'rent' => 'tenant', 'lease' => 'landlord');
        $clientType = isset($typeMap[$lead['interest_type']]) ? $typeMap[$lead['interest_type']] : 'buyer';

        $ownTransaction = !$this->conn->inTransaction();
        try {
            if ($ownTransaction) {
                $this->conn->beginTransaction();
            }
            $clientId = $clientModel->createClient(array(
                'tenant_id' => $tenantId,
                'lead_id' => $id,
                'first_name' => $lead['first_name'],
                'last_name' => $lead['last_name'],
                'email' => $lead['email'],
                'phone' => $lead['phone'],
                'client_type' => $clientType,
                'status' => 'active',
                'notes' => $lead['notes']
            ));
            if (!$clientId) {
                throw new RuntimeException('Client insert failed');
            }
            $this->updateStatus($id, $tenantId, 'won');
            if ($ownTransaction) {
                $this->conn->commit();
            }
            return (int)$clientId;
        } catch (Exception $e) {
            if ($ownTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Lead conversion failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get lead sources in use by a tenant
     * @param int $tenantId Tenant ID
     * @return array Sources
     */
    public function getSources($tenantId) {
        $sql = "SELECT DISTINCT source FROM {$this->table} WHERE tenant_id = :tenant_id AND source IS NOT NULL AND source <> '' ORDER BY source";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Hot leads: open leads graded A or B, best score first
     * @param int $tenantId Tenant ID
     * @param int $limit Max rows
     * @param int|null $userId Only leads assigned to this user (null = all)
     * @return array
     */
    public function getHotLeads($tenantId, $limit = 5, $userId = null) {
        $sql = "SELECT l.id, l.first_name, l.last_name, l.email, l.status, l.assigned_to,
                       ls.score, ls.grade,
                       CONCAT(u.first_name, ' ', u.last_name) AS assigned_to_name
                FROM lead_scores ls
                INNER JOIN {$this->table} l ON l.id = ls.lead_id
                LEFT JOIN users u ON u.id = l.assigned_to
                WHERE l.tenant_id = :tenant_id
                  AND ls.grade IN ('A', 'B')
                  AND l.status NOT IN ('won', 'lost')";
        if ($userId !== null) {
            $sql .= " AND l.assigned_to = :user_id";
        }
        $sql .= " ORDER BY ls.score DESC, l.created_at DESC LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Normalise user-entered tags: trim, de-duplicate (case-insensitive),
     * strip commas/control chars, max 50 chars each, total <= 500 chars.
     * @param string|array $tags Comma-separated string or array
     * @return string|null Comma-separated tags ("a, b") or null when empty
     */
    public static function normalizeTags($tags) {
        if (is_array($tags)) {
            $parts = $tags;
        } else {
            $parts = explode(',', (string)$tags);
        }
        $clean = array();
        $seen = array();
        foreach ($parts as $tag) {
            $tag = trim(preg_replace('/[\x00-\x1F\x7F,]+/u', ' ', (string)$tag));
            $tag = preg_replace('/\s+/u', ' ', $tag);
            if ($tag === '') {
                continue;
            }
            if (function_exists('mb_substr')) {
                $tag = mb_substr($tag, 0, 50);
            } else {
                $tag = substr($tag, 0, 50);
            }
            $key = strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $candidate = implode(', ', array_merge($clean, array($tag)));
            if (strlen($candidate) > 500) {
                break;
            }
            $clean[] = $tag;
        }
        return empty($clean) ? null : implode(', ', $clean);
    }

    /**
     * Split a stored tags string into an array
     * @param string|null $tags
     * @return array
     */
    public static function tagList($tags) {
        if ($tags === null || $tags === '') {
            return array();
        }
        return array_values(array_filter(array_map('trim', explode(',', $tags)), 'strlen'));
    }
}

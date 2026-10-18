<?php
// FILE: /app/models/Deal.php

/**
 * SplashEstate CRM - Deal Model
 * Handles deal/pipeline management
 */

class Deal extends Model {

    protected $table = 'deals';

    /**
     * Pipeline stages in order (mirror the deals.stage enum) => label
     * @return array
     */
    public static function getStages() {
        return array(
            'lead' => 'Lead',
            'qualified' => 'Qualified',
            'viewing' => 'Viewing',
            'offer' => 'Offer',
            'negotiation' => 'Negotiation',
            'contract' => 'Contract',
            'closed_won' => 'Closed Won',
            'closed_lost' => 'Closed Lost'
        );
    }

    /**
     * Whether a value is a valid stage
     * @param string $stage
     * @return bool
     */
    public static function isValidStage($stage) {
        return is_string($stage) && array_key_exists($stage, self::getStages());
    }

    /**
     * Badge CSS class for a stage
     * @param string $stage
     * @return string
     */
    public static function stageBadgeClass($stage) {
        $map = array(
            'lead' => 'badge-secondary',
            'qualified' => 'badge-info',
            'viewing' => 'badge-info',
            'offer' => 'badge-primary',
            'negotiation' => 'badge-warning',
            'contract' => 'badge-warning',
            'closed_won' => 'badge-success',
            'closed_lost' => 'badge-danger'
        );
        return isset($map[$stage]) ? $map[$stage] : 'badge-secondary';
    }

    /**
     * Shared WHERE clause for list/count/pipeline queries.
     * Filters: stage, assigned_to, client_id, property_id, search
     */
    private function buildWhere($tenantId, $filters, &$params) {
        $where = "WHERE d.tenant_id = :tenant_id";
        $params = array(':tenant_id' => (int)$tenantId);

        if (!empty($filters['stage']) && self::isValidStage($filters['stage'])) {
            $where .= " AND d.stage = :stage";
            $params[':stage'] = $filters['stage'];
        }
        if (!empty($filters['assigned_to'])) {
            $where .= " AND d.assigned_to = :assigned_to";
            $params[':assigned_to'] = (int)$filters['assigned_to'];
        }
        if (!empty($filters['client_id'])) {
            $where .= " AND d.client_id = :client_id";
            $params[':client_id'] = (int)$filters['client_id'];
        }
        if (!empty($filters['property_id'])) {
            $where .= " AND d.property_id = :property_id";
            $params[':property_id'] = (int)$filters['property_id'];
        }
        if (isset($filters['search']) && trim((string)$filters['search']) !== '') {
            $where .= " AND d.title LIKE :search";
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }
        return $where;
    }

    /**
     * SELECT ... FROM ... JOIN part shared by list queries
     * @return string
     */
    private function selectWithInfo() {
        return "SELECT d.*,
                CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')) AS client_name,
                p.title AS property_title,
                CONCAT(u.first_name, ' ', u.last_name) AS agent_name
                FROM {$this->table} d
                LEFT JOIN clients c ON d.client_id = c.id AND c.tenant_id = d.tenant_id
                LEFT JOIN properties p ON d.property_id = p.id AND p.tenant_id = d.tenant_id
                LEFT JOIN users u ON d.assigned_to = u.id AND u.tenant_id = d.tenant_id ";
    }

    /**
     * Get deals with client/property/agent names
     * @param int $tenantId Tenant ID
     * @param int $page Page number
     * @param int $perPage Records per page
     * @param array $filters Filters
     * @return array Deals
     */
    public function getDealsWithInfo($tenantId, $page = 1, $perPage = 20, $filters = array()) {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $params = array();
        $where = $this->buildWhere($tenantId, $filters, $params);
        $sql = $this->selectWithInfo() . $where . " ORDER BY d.created_at DESC, d.id DESC LIMIT :limit OFFSET :offset";

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
     * Count deals matching the same filters as getDealsWithInfo()
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @return int
     */
    public function count($tenantId, $filters = array()) {
        $params = array();
        $where = $this->buildWhere($tenantId, $filters, $params);
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} d {$where}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Build the Kanban pipeline: every stage with its deals, count, total and
     * weighted (value x probability) value.
     * @param int $tenantId Tenant ID
     * @param array $filters Filters (stage is ignored here)
     * @param int $limit Safety cap on the number of deals loaded
     * @return array stage => array(label, deals, count, total, weighted)
     */
    public function getPipeline($tenantId, $filters = array(), $limit = 1000) {
        unset($filters['stage']);
        $params = array();
        $where = $this->buildWhere($tenantId, $filters, $params);
        $sql = $this->selectWithInfo() . $where . " ORDER BY d.expected_close_date IS NULL, d.expected_close_date ASC, d.id DESC LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();

        $pipeline = array();
        foreach (self::getStages() as $stage => $label) {
            $pipeline[$stage] = array('label' => $label, 'deals' => array(), 'count' => 0, 'total' => 0.0, 'weighted' => 0.0);
        }
        foreach ($stmt->fetchAll() as $deal) {
            $stage = isset($pipeline[$deal['stage']]) ? $deal['stage'] : 'lead';
            $value = (float)$deal['deal_value'];
            $pipeline[$stage]['deals'][] = $deal;
            $pipeline[$stage]['count']++;
            $pipeline[$stage]['total'] += $value;
            $pipeline[$stage]['weighted'] += $value * ((int)$deal['probability'] / 100);
        }
        return $pipeline;
    }

    /**
     * Get one deal with client, property and assignee details
     * @param int $id Deal ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function getDealWithInfo($id, $tenantId) {
        $sql = "SELECT d.*,
                CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')) AS client_name,
                c.email AS client_email, c.phone AS client_phone,
                p.title AS property_title, p.address AS property_address, p.city AS property_city,
                p.price AS property_price, p.listing_type AS property_listing_type,
                CONCAT(u.first_name, ' ', u.last_name) AS agent_name, u.email AS agent_email
                FROM {$this->table} d
                LEFT JOIN clients c ON d.client_id = c.id AND c.tenant_id = d.tenant_id
                LEFT JOIN properties p ON d.property_id = p.id AND p.tenant_id = d.tenant_id
                LEFT JOIN users u ON d.assigned_to = u.id AND u.tenant_id = d.tenant_id
                WHERE d.id = :id AND d.tenant_id = :tenant_id
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Deals linked to a property
     * @param int $propertyId Property ID
     * @param int $tenantId Tenant ID
     * @return array
     */
    public function getByProperty($propertyId, $tenantId) {
        return $this->getDealsWithInfo($tenantId, 1, 100, array('property_id' => (int)$propertyId));
    }

    /**
     * Tasks related to a deal
     * @param int $dealId Deal ID
     * @param int $tenantId Tenant ID
     * @return array
     */
    public function getRelatedTasks($dealId, $tenantId) {
        $sql = "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS assigned_name
                FROM tasks t
                LEFT JOIN users u ON t.assigned_to = u.id
                WHERE t.tenant_id = :tenant_id AND t.related_to_type = 'deal' AND t.related_to_id = :deal_id
                ORDER BY (t.status IN ('completed', 'cancelled')) ASC, t.due_date IS NULL, t.due_date ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':deal_id', (int)$dealId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Stage history derived from the activity log
     * (entries written as action "stage_changed:<old>:<new>").
     * @param int $dealId Deal ID
     * @param int $tenantId Tenant ID
     * @return array Rows with from_stage, to_stage, user_name, created_at (newest first)
     */
    public function getStageHistory($dealId, $tenantId) {
        $sql = "SELECT al.action, al.created_at, CONCAT(u.first_name, ' ', u.last_name) AS user_name
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.tenant_id = :tenant_id AND al.entity_type = 'deal' AND al.entity_id = :deal_id
                  AND al.action LIKE 'stage_changed:%'
                ORDER BY al.created_at DESC, al.id DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':deal_id', (int)$dealId, PDO::PARAM_INT);
        $stmt->execute();

        $history = array();
        foreach ($stmt->fetchAll() as $row) {
            $parts = explode(':', $row['action']);
            $history[] = array(
                'from_stage' => isset($parts[1]) ? $parts[1] : '',
                'to_stage' => isset($parts[2]) ? $parts[2] : '',
                'user_name' => $row['user_name'],
                'created_at' => $row['created_at']
            );
        }
        return $history;
    }

    /**
     * Get active deals count
     * @param int $tenantId Tenant ID
     * @return int Count
     */
    public function getActiveDealsCount($tenantId) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}
                WHERE tenant_id = :tenant_id
                AND stage NOT IN ('closed_won', 'closed_lost')";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();

        return (int)$result['count'];
    }

    /**
     * Create deal
     * @param array $data Deal data (must include tenant_id and title)
     * @return int|bool Deal ID or false
     */
    public function createDeal($data) {
        $columns = array('tenant_id', 'client_id', 'property_id', 'assigned_to', 'title', 'deal_value', 'commission',
            'stage', 'probability', 'expected_close_date', 'actual_close_date', 'notes');
        $row = array();
        foreach ($columns as $column) {
            $row[$column] = array_key_exists($column, $data) ? $data[$column] : null;
        }
        if (!self::isValidStage($row['stage'])) {
            $row['stage'] = 'lead';
        }
        if ($row['probability'] === null) {
            $row['probability'] = 0;
        }
        $id = $this->create($row);
        return $id ? (int)$id : false;
    }

    /**
     * Update deal stage. Closing a deal stamps actual_close_date (if not set);
     * re-opening it clears the date.
     * @param int $id Deal ID
     * @param int $tenantId Tenant ID
     * @param string $stage New stage
     * @return bool Success status
     */
    public function updateStage($id, $tenantId, $stage) {
        if (!self::isValidStage($stage)) {
            return false;
        }
        $sql = "UPDATE {$this->table}
                SET stage = :stage,
                    actual_close_date = CASE
                        WHEN :stage_a IN ('closed_won', 'closed_lost') THEN COALESCE(actual_close_date, CURDATE())
                        ELSE NULL END,
                    probability = CASE
                        WHEN :stage_b = 'closed_won' THEN 100
                        WHEN :stage_c = 'closed_lost' THEN 0
                        ELSE probability END,
                    updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':stage', $stage);
        $stmt->bindValue(':stage_a', $stage);
        $stmt->bindValue(':stage_b', $stage);
        $stmt->bindValue(':stage_c', $stage);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        return $stmt->execute();
    }
}

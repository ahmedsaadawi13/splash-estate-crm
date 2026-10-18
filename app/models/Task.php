<?php
// FILE: /app/models/Task.php

/**
 * SplashEstate CRM - Task Model
 * Handles task management
 */

class Task extends Model {

    protected $table = 'tasks';

    const STATUSES = array('pending', 'in_progress', 'completed', 'cancelled');
    const PRIORITIES = array('low', 'medium', 'high', 'urgent');
    const RELATED_TYPES = array('lead', 'client', 'property', 'deal');

    /**
     * SELECT with assignee/creator names and the related record's display name
     * @return string
     */
    private function baseSelect() {
        return "SELECT t.*,
                CONCAT(u.first_name, ' ', u.last_name) AS assigned_to_name,
                CONCAT(u2.first_name, ' ', u2.last_name) AS created_by_name,
                CASE t.related_to_type
                    WHEN 'lead' THEN (SELECT CONCAT(rl.first_name, ' ', IFNULL(rl.last_name, '')) FROM leads rl WHERE rl.id = t.related_to_id AND rl.tenant_id = t.tenant_id)
                    WHEN 'client' THEN (SELECT CONCAT(rc.first_name, ' ', IFNULL(rc.last_name, '')) FROM clients rc WHERE rc.id = t.related_to_id AND rc.tenant_id = t.tenant_id)
                    WHEN 'property' THEN (SELECT rp.title FROM properties rp WHERE rp.id = t.related_to_id AND rp.tenant_id = t.tenant_id)
                    WHEN 'deal' THEN (SELECT rd.title FROM deals rd WHERE rd.id = t.related_to_id AND rd.tenant_id = t.tenant_id)
                    ELSE NULL
                END AS related_name,
                (t.due_date IS NOT NULL AND t.due_date < NOW() AND t.status IN ('pending', 'in_progress')) AS is_overdue
                FROM {$this->table} t
                LEFT JOIN users u ON t.assigned_to = u.id
                LEFT JOIN users u2 ON t.created_by = u2.id";
    }

    /**
     * WHERE clause for list/count queries.
     * Filters: status, priority, assigned_to, overdue (truthy), related_to_type + related_to_id, search
     */
    private function buildWhere($tenantId, $filters, &$params) {
        $where = array('t.tenant_id = :tenant_id');
        $params = array(':tenant_id' => (int)$tenantId);

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'open') {
                $where[] = "t.status IN ('pending', 'in_progress')";
            } else {
                $where[] = 't.status = :status';
                $params[':status'] = $filters['status'];
            }
        }
        if (!empty($filters['priority'])) {
            $where[] = 't.priority = :priority';
            $params[':priority'] = $filters['priority'];
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = 't.assigned_to = :assigned_to';
            $params[':assigned_to'] = (int)$filters['assigned_to'];
        }
        if (!empty($filters['overdue'])) {
            $where[] = "t.due_date IS NOT NULL AND t.due_date < NOW() AND t.status IN ('pending', 'in_progress')";
        }
        if (!empty($filters['related_to_type']) && !empty($filters['related_to_id'])) {
            $where[] = 't.related_to_type = :related_to_type AND t.related_to_id = :related_to_id';
            $params[':related_to_type'] = $filters['related_to_type'];
            $params[':related_to_id'] = (int)$filters['related_to_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(t.title LIKE :s1 OR t.description LIKE :s2)';
            $params[':s1'] = '%' . $filters['search'] . '%';
            $params[':s2'] = '%' . $filters['search'] . '%';
        }
        return ' WHERE ' . implode(' AND ', $where);
    }

    /**
     * Get tasks with user info
     * @param int $tenantId Tenant ID
     * @param int $page Page number
     * @param int $perPage Records per page
     * @param array $filters Filters (see buildWhere)
     * @return array Tasks
     */
    public function getTasksWithInfo($tenantId, $page = 1, $perPage = 20, $filters = array()) {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $params = array();
        $sql = $this->baseSelect() . $this->buildWhere($tenantId, $filters, $params)
             . " ORDER BY FIELD(t.status, 'in_progress', 'pending', 'completed', 'cancelled'),
                         t.due_date IS NULL, t.due_date ASC,
                         FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.id DESC
                 LIMIT :limit OFFSET :offset";

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
     * Count tasks (overrides Model::count so overdue/related/search filters work)
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @return int
     */
    public function count($tenantId, $filters = array()) {
        $params = array();
        $sql = "SELECT COUNT(*) FROM {$this->table} t" . $this->buildWhere($tenantId, $filters, $params);
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get one task with names
     * @param int $id Task ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function getTaskWithInfo($id, $tenantId) {
        $stmt = $this->conn->prepare($this->baseSelect() . " WHERE t.id = :id AND t.tenant_id = :tenant_id LIMIT 1");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Tasks linked to a record (lead, client, property, deal)
     * @param int $tenantId Tenant ID
     * @param string $type Related type
     * @param int $relatedId Related ID
     * @return array
     */
    public function getByRelated($tenantId, $type, $relatedId) {
        return $this->getTasksWithInfo($tenantId, 1, 100, array('related_to_type' => $type, 'related_to_id' => $relatedId));
    }

    /**
     * Count tasks by assignee
     * @param int $tenantId Tenant ID
     * @param int $userId User ID
     * @param string $status Task status
     * @return int Count
     */
    public function countByAssignee($tenantId, $userId, $status = null) {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE tenant_id = :tenant_id AND assigned_to = :user_id";
        if ($status !== null) {
            $sql .= " AND status = :status";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        if ($status !== null) {
            $stmt->bindValue(':status', $status);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get upcoming (not yet due) open tasks for a user
     * @param int $tenantId Tenant ID
     * @param int $userId User ID
     * @param int $limit Limit
     * @return array Tasks
     */
    public function getUpcoming($tenantId, $userId, $limit = 5) {
        $sql = "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS assigned_to_name
                FROM {$this->table} t
                LEFT JOIN users u ON t.assigned_to = u.id
                WHERE t.tenant_id = :tenant_id
                AND t.assigned_to = :user_id
                AND t.status IN ('pending', 'in_progress')
                AND t.due_date >= NOW()
                ORDER BY t.due_date ASC
                LIMIT :limit";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Overdue open tasks assigned to a user, oldest due first
     * @param int $tenantId Tenant ID
     * @param int $userId User ID
     * @param int $limit Limit
     * @return array
     */
    public function getOverdueForUser($tenantId, $userId, $limit = 5) {
        $sql = "SELECT t.id, t.title, t.due_date, t.priority, t.status, t.related_to_type, t.related_to_id
                FROM {$this->table} t
                WHERE t.tenant_id = :tenant_id
                AND t.assigned_to = :user_id
                AND t.status IN ('pending', 'in_progress')
                AND t.due_date IS NOT NULL AND t.due_date < NOW()
                ORDER BY t.due_date ASC
                LIMIT :limit";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Number of overdue open tasks assigned to a user
     * @param int $tenantId Tenant ID
     * @param int $userId User ID
     * @return int
     */
    public function countOverdueForUser($tenantId, $userId) {
        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE tenant_id = :tenant_id AND assigned_to = :user_id
                AND status IN ('pending', 'in_progress')
                AND due_date IS NOT NULL AND due_date < NOW()";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Whether the record a task points to exists in the tenant
     * @param int $tenantId Tenant ID
     * @param string $type lead|client|property|deal
     * @param int $id Record ID
     * @return string|false Display name, or false when not found
     */
    public function findRelated($tenantId, $type, $id) {
        $queries = array(
            'lead' => "SELECT CONCAT(first_name, ' ', IFNULL(last_name, '')) FROM leads WHERE id = :id AND tenant_id = :tenant_id",
            'client' => "SELECT CONCAT(first_name, ' ', IFNULL(last_name, '')) FROM clients WHERE id = :id AND tenant_id = :tenant_id",
            'property' => "SELECT title FROM properties WHERE id = :id AND tenant_id = :tenant_id",
            'deal' => "SELECT title FROM deals WHERE id = :id AND tenant_id = :tenant_id"
        );
        if (!isset($queries[$type]) || (int)$id <= 0) {
            return false;
        }
        $stmt = $this->conn->prepare($queries[$type]);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        $name = $stmt->fetchColumn();
        return $name === false ? false : trim((string)$name);
    }

    /**
     * Create task
     * @param array $data Task data
     * @return int|false Task ID or false
     */
    public function createTask($data) {
        $sql = "INSERT INTO {$this->table}
                (tenant_id, assigned_to, created_by, related_to_type, related_to_id, title, description, due_date, priority, status, completed_at, created_at)
                VALUES (:tenant_id, :assigned_to, :created_by, :related_to_type, :related_to_id, :title, :description, :due_date, :priority, :status, :completed_at, NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$data['tenant_id'], PDO::PARAM_INT);
        foreach (array('assigned_to', 'created_by', 'related_to_id') as $intField) {
            $value = !empty($data[$intField]) ? (int)$data[$intField] : null;
            $stmt->bindValue(':' . $intField, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        }
        $stmt->bindValue(':related_to_type', !empty($data['related_to_type']) ? $data['related_to_type'] : null);
        $stmt->bindValue(':title', $data['title']);
        $stmt->bindValue(':description', isset($data['description']) && $data['description'] !== '' ? $data['description'] : null);
        $stmt->bindValue(':due_date', !empty($data['due_date']) ? $data['due_date'] : null);
        $stmt->bindValue(':priority', !empty($data['priority']) ? $data['priority'] : 'medium');
        $status = !empty($data['status']) ? $data['status'] : 'pending';
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':completed_at', $status === 'completed' ? date('Y-m-d H:i:s') : null);

        if ($stmt->execute()) {
            return (int)$this->conn->lastInsertId();
        }
        return false;
    }

    /**
     * Mark task as completed (no-op if already completed)
     * @param int $id Task ID
     * @param int $tenantId Tenant ID
     * @return bool True if the task changed to completed
     */
    public function markCompleted($id, $tenantId) {
        $sql = "UPDATE {$this->table} SET status = 'completed', completed_at = NOW(), updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant_id AND status <> 'completed'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}

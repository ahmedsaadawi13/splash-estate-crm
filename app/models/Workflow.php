<?php
// FILE: /app/models/Workflow.php

/**
 * SplashEstate CRM - Workflow Model
 * Workflows (tenant scoped) plus their child tables: workflow_actions,
 * workflow_executions and workflow_action_logs (no tenant_id of their own;
 * every accessor here verifies ownership through workflows.tenant_id).
 */

class Workflow extends Model {

    protected $table = 'workflows';

    /** Trigger types allowed by the workflows.trigger_type enum */
    const TRIGGER_TYPES = array(
        'lead_created', 'lead_updated', 'lead_status_changed', 'client_created',
        'property_created', 'deal_created', 'deal_status_changed', 'deal_won',
        'deal_lost', 'task_created', 'task_completed', 'scheduled'
    );

    /** Action types allowed by the workflow_actions.action_type enum */
    const ACTION_TYPES = array(
        'send_email', 'create_task', 'update_field', 'assign_to_user', 'add_tag',
        'send_webhook', 'wait', 'create_deal', 'send_notification'
    );

    /**
     * Paginated workflows for a tenant with creator name, action count and run statistics.
     * Compatible with Model::getAll(). Supported filters: status, trigger_type.
     * @param int $tenantId Tenant ID
     * @param int $page Page number
     * @param int $perPage Rows per page
     * @param array $filters Filters
     * @return array
     */
    public function getAll($tenantId, $page = 1, $perPage = 20, $filters = array()) {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        list($where, $params) = $this->buildFilters($tenantId, $filters);

        $sql = "SELECT w.*, u.first_name AS creator_first_name, u.last_name AS creator_last_name,
                    (SELECT COUNT(*) FROM workflow_actions wa WHERE wa.workflow_id = w.id) AS action_count,
                    (SELECT COUNT(*) FROM workflow_executions we WHERE we.workflow_id = w.id) AS total_executions,
                    (SELECT COUNT(*) FROM workflow_executions we WHERE we.workflow_id = w.id AND we.status = 'completed') AS successful_executions,
                    (SELECT COUNT(*) FROM workflow_executions we WHERE we.workflow_id = w.id AND we.status = 'failed') AS failed_executions,
                    (SELECT MAX(we.created_at) FROM workflow_executions we WHERE we.workflow_id = w.id) AS last_execution
                FROM {$this->table} w
                LEFT JOIN users u ON w.created_by = u.id
                WHERE {$where}
                ORDER BY w.execution_order ASC, w.created_at DESC, w.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count workflows for a tenant (same filters as getAll()).
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @return int
     */
    public function count($tenantId, $filters = array()) {
        list($where, $params) = $this->buildFilters($tenantId, $filters);
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} w WHERE {$where}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Build the WHERE clause for list queries
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @return array [sql, params]
     */
    private function buildFilters($tenantId, $filters) {
        $where = 'w.tenant_id = :tenant_id';
        $params = array(':tenant_id' => (int)$tenantId);

        if (!empty($filters['status']) && in_array($filters['status'], array('active', 'inactive'), true)) {
            $where .= ' AND w.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['trigger_type']) && in_array($filters['trigger_type'], self::TRIGGER_TYPES, true)) {
            $where .= ' AND w.trigger_type = :trigger_type';
            $params[':trigger_type'] = $filters['trigger_type'];
        }
        return array($where, $params);
    }

    /**
     * Active workflows of a tenant for a trigger type, in execution order
     * @param int $tenantId Tenant ID
     * @param string $triggerType Trigger type
     * @return array
     */
    public function getByTrigger($tenantId, $triggerType) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM {$this->table}
             WHERE tenant_id = :tenant_id AND trigger_type = :trigger_type AND status = 'active'
             ORDER BY execution_order ASC, id ASC"
        );
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':trigger_type', (string)$triggerType);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Active 'scheduled' workflows (all tenants) that are due to run.
     * hourly = every 60 min, daily = every 24 h, weekly = every 7 days (1 minute
     * tolerance so a once-a-minute cron does not drift).
     * @param int $limit Max workflows
     * @return array
     */
    public function getDueScheduled($limit = 50) {
        $stmt = $this->conn->prepare(
            "SELECT w.*, TIMESTAMPDIFF(SECOND, w.last_run_at, NOW()) AS seconds_since_run
             FROM {$this->table} w
             INNER JOIN tenants t ON t.id = w.tenant_id AND t.status = 'active'
             WHERE w.trigger_type = 'scheduled' AND w.status = 'active'
             ORDER BY w.last_run_at IS NOT NULL, w.last_run_at ASC, w.id ASC
             LIMIT 500"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $intervals = array('hourly' => 3600, 'daily' => 86400, 'weekly' => 604800);
        $due = array();
        foreach ($rows as $row) {
            $config = json_decode((string)$row['trigger_config'], true);
            $frequency = is_array($config) && isset($config['frequency']) ? $config['frequency'] : 'daily';
            $interval = isset($intervals[$frequency]) ? $intervals[$frequency] : 86400;

            if ($row['last_run_at'] === null || (int)$row['seconds_since_run'] >= $interval - 60) {
                $due[] = $row;
            }
            if (count($due) >= $limit) {
                break;
            }
        }
        return $due;
    }

    /**
     * Record that a scheduled workflow ran now
     * @param int $id Workflow ID
     * @param int $tenantId Tenant ID
     * @return bool
     */
    public function markRun($id, $tenantId) {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET last_run_at = NOW() WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Active users of a tenant (for assignee / recipient pickers)
     * @param int $tenantId Tenant ID
     * @return array id => "First Last"
     */
    public function getTenantUsers($tenantId) {
        $stmt = $this->conn->prepare(
            "SELECT id, first_name, last_name FROM users
             WHERE tenant_id = :tenant_id AND status = 'active'
             ORDER BY first_name, last_name"
        );
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        $users = array();
        foreach ($stmt->fetchAll() as $row) {
            $users[(int)$row['id']] = trim($row['first_name'] . ' ' . $row['last_name']);
        }
        return $users;
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * Actions of a workflow in execution order (caller must have verified the workflow's tenant)
     * @param int $workflowId Workflow ID
     * @return array
     */
    public function getActions($workflowId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM workflow_actions WHERE workflow_id = :workflow_id ORDER BY execution_order ASC, id ASC"
        );
        $stmt->bindValue(':workflow_id', (int)$workflowId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Add an action to a workflow
     * @param int $workflowId Workflow ID
     * @param array $actionData action_type, action_config (array or JSON string), execution_order, delay_minutes
     * @return int|false Action ID
     */
    public function addAction($workflowId, $actionData) {
        if (!isset($actionData['action_type']) || !in_array($actionData['action_type'], self::ACTION_TYPES, true)) {
            return false;
        }
        $config = isset($actionData['action_config']) ? $actionData['action_config'] : array();
        if (!is_string($config)) {
            $config = json_encode($config);
            if ($config === false) {
                $config = '{}';
            }
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO workflow_actions (workflow_id, action_type, action_config, execution_order, delay_minutes)
             VALUES (:workflow_id, :action_type, :action_config, :execution_order, :delay_minutes)"
        );
        $stmt->bindValue(':workflow_id', (int)$workflowId, PDO::PARAM_INT);
        $stmt->bindValue(':action_type', $actionData['action_type']);
        $stmt->bindValue(':action_config', $config);
        $stmt->bindValue(':execution_order', isset($actionData['execution_order']) ? (int)$actionData['execution_order'] : 0, PDO::PARAM_INT);
        $stmt->bindValue(':delay_minutes', isset($actionData['delay_minutes']) ? max(0, (int)$actionData['delay_minutes']) : 0, PDO::PARAM_INT);

        return $stmt->execute() ? (int)$this->conn->lastInsertId() : false;
    }

    /**
     * Delete every action of a workflow
     * @param int $workflowId Workflow ID
     * @return bool
     */
    public function removeAllActions($workflowId) {
        $stmt = $this->conn->prepare("DELETE FROM workflow_actions WHERE workflow_id = :workflow_id");
        $stmt->bindValue(':workflow_id', (int)$workflowId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Create a workflow together with its actions (one transaction)
     * @param array $data Workflow columns
     * @param array $actions Actions (see addAction())
     * @return int|false Workflow ID
     */
    public function createWithActions($data, $actions) {
        $this->conn->beginTransaction();
        try {
            $id = $this->create($data);
            if (!$id) {
                throw new RuntimeException('Insert failed');
            }
            foreach ($actions as $action) {
                if ($this->addAction($id, $action) === false) {
                    throw new RuntimeException('Invalid action');
                }
            }
            $this->conn->commit();
            return (int)$id;
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log('Workflow create failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a workflow and replace its actions (one transaction).
     * Actions are replaced only when $actions is an array; existing execution
     * logs for removed actions are deleted by the FK cascade.
     * @param int $id Workflow ID
     * @param int $tenantId Tenant ID
     * @param array $data Workflow columns
     * @param array|null $actions New actions
     * @return bool
     */
    public function updateWithActions($id, $tenantId, $data, $actions = null) {
        if (!$this->getById($id, $tenantId)) {
            return false;
        }
        $this->conn->beginTransaction();
        try {
            $this->update($id, $tenantId, $data);
            if (is_array($actions)) {
                $this->removeAllActions($id);
                foreach ($actions as $action) {
                    if ($this->addAction($id, $action) === false) {
                        throw new RuntimeException('Invalid action');
                    }
                }
            }
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log('Workflow update failed: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Executions
    // ------------------------------------------------------------------

    /**
     * Execution statistics for a workflow
     * @param int $workflowId Workflow ID
     * @return array
     */
    public function getStatistics($workflowId) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total_executions,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS successful,
                    COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(CASE WHEN status IN ('pending','running') THEN 1 ELSE 0 END), 0) AS running,
                    MAX(created_at) AS last_execution
             FROM workflow_executions WHERE workflow_id = :workflow_id"
        );
        $stmt->bindValue(':workflow_id', (int)$workflowId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Paginated executions of a workflow, newest first, with per-execution action counts
     * @param int $workflowId Workflow ID
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array
     */
    public function getExecutions($workflowId, $limit = 20, $offset = 0) {
        $stmt = $this->conn->prepare(
            "SELECT we.*,
                    (SELECT COUNT(*) FROM workflow_action_logs l WHERE l.execution_id = we.id) AS action_total,
                    (SELECT COUNT(*) FROM workflow_action_logs l WHERE l.execution_id = we.id AND l.status = 'completed') AS action_completed,
                    (SELECT COUNT(*) FROM workflow_action_logs l WHERE l.execution_id = we.id AND l.status = 'failed') AS action_failed,
                    (SELECT COUNT(*) FROM workflow_action_logs l WHERE l.execution_id = we.id AND l.status = 'scheduled') AS action_scheduled
             FROM workflow_executions we
             WHERE we.workflow_id = :workflow_id
             ORDER BY we.created_at DESC, we.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':workflow_id', (int)$workflowId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, (int)$offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count executions of a workflow
     * @param int $workflowId Workflow ID
     * @return int
     */
    public function countExecutions($workflowId) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM workflow_executions WHERE workflow_id = :workflow_id");
        $stmt->bindValue(':workflow_id', (int)$workflowId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * An execution, only if its workflow belongs to the tenant
     * @param int $executionId Execution ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function getExecutionForTenant($executionId, $tenantId) {
        $stmt = $this->conn->prepare(
            "SELECT we.*, w.name AS workflow_name, w.trigger_type, w.tenant_id
             FROM workflow_executions we
             INNER JOIN workflows w ON w.id = we.workflow_id
             WHERE we.id = :id AND w.tenant_id = :tenant_id
             LIMIT 1"
        );
        $stmt->bindValue(':id', (int)$executionId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Action logs of an execution with the action type/config/delay
     * (caller must have verified the execution's tenant)
     * @param int $executionId Execution ID
     * @return array
     */
    public function getActionLogs($executionId) {
        $stmt = $this->conn->prepare(
            "SELECT l.*, a.action_type, a.action_config, a.delay_minutes, a.execution_order
             FROM workflow_action_logs l
             INNER JOIN workflow_actions a ON a.id = l.action_id
             WHERE l.execution_id = :execution_id
             ORDER BY a.execution_order ASC, l.id ASC"
        );
        $stmt->bindValue(':execution_id', (int)$executionId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

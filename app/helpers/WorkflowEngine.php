<?php
// FILE: /app/helpers/WorkflowEngine.php

/**
 * SplashEstate CRM - Workflow Execution Engine
 *
 * Runs tenant workflows when application events fire (see dispatchEvent()),
 * on a schedule (trigger_type 'scheduled', driven by cron.php) and on demand
 * (the "Test run" button).
 *
 * Execution model:
 *   - one workflow_executions row per run (status running -> completed|failed)
 *   - one workflow_action_logs row per action
 *   - actions with delay_minutes > 0 are stored as status 'scheduled' with
 *     scheduled_for = trigger time + delay and executed later by runDueActions();
 *     the execution stays 'running' until its last scheduled action finishes
 *   - a failing action is logged and the remaining actions still run; the
 *     execution ends 'failed' if any action failed
 *
 * Every write is tenant scoped and limited to whitelisted entity types/fields.
 */

class WorkflowEngine {

    /** Max records a scheduled workflow processes per run */
    const SCHEDULED_RECORD_CAP = 100;

    /** @var Workflow */
    private $workflowModel;

    /** @var PDO */
    private $conn;

    /** Entity type => table / link / assignee column */
    private static $entities = array(
        'lead' => array('table' => 'leads', 'link' => 'leads/view/', 'assignee' => 'assigned_to', 'label' => 'Lead'),
        'client' => array('table' => 'clients', 'link' => 'clients/view/', 'assignee' => null, 'label' => 'Client'),
        'property' => array('table' => 'properties', 'link' => 'properties/view/', 'assignee' => 'listing_agent_id', 'label' => 'Property'),
        'deal' => array('table' => 'deals', 'link' => 'deals/view/', 'assignee' => 'assigned_to', 'label' => 'Deal'),
        'task' => array('table' => 'tasks', 'link' => 'tasks/view/', 'assignee' => 'assigned_to', 'label' => 'Task')
    );

    public function __construct() {
        require_once APP_PATH . '/models/Workflow.php';
        $this->workflowModel = new Workflow();
        $this->conn = $this->workflowModel->getConnection();
    }

    // ==================================================================
    // Static metadata (shared with the controller / views)
    // ==================================================================

    /**
     * Fields the update_field action may change, per entity type.
     * type: enum (values), text (max), number, int (min/max), date
     * @return array
     */
    public static function getUpdatableFields() {
        return array(
            'lead' => array(
                'status' => array('label' => 'Status', 'type' => 'enum', 'values' => array('new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost')),
                'source' => array('label' => 'Source', 'type' => 'text', 'max' => 100),
                'interest_type' => array('label' => 'Interest type', 'type' => 'enum', 'values' => array('buy', 'sell', 'rent', 'lease')),
                'budget_min' => array('label' => 'Budget min', 'type' => 'number'),
                'budget_max' => array('label' => 'Budget max', 'type' => 'number'),
                'notes' => array('label' => 'Notes', 'type' => 'text', 'max' => 65000)
            ),
            'client' => array(
                'status' => array('label' => 'Status', 'type' => 'enum', 'values' => array('active', 'inactive')),
                'client_type' => array('label' => 'Client type', 'type' => 'enum', 'values' => array('buyer', 'seller', 'landlord', 'tenant')),
                'notes' => array('label' => 'Notes', 'type' => 'text', 'max' => 65000)
            ),
            'property' => array(
                'status' => array('label' => 'Status', 'type' => 'enum', 'values' => array('available', 'pending', 'sold', 'rented', 'off_market'))
            ),
            'deal' => array(
                'stage' => array('label' => 'Stage', 'type' => 'enum', 'values' => array('lead', 'qualified', 'viewing', 'offer', 'negotiation', 'contract', 'closed_won', 'closed_lost')),
                'probability' => array('label' => 'Probability (%)', 'type' => 'int', 'min' => 0, 'max' => 100),
                'expected_close_date' => array('label' => 'Expected close date', 'type' => 'date'),
                'notes' => array('label' => 'Notes', 'type' => 'text', 'max' => 65000)
            ),
            'task' => array(
                'status' => array('label' => 'Status', 'type' => 'enum', 'values' => array('pending', 'in_progress', 'completed', 'cancelled')),
                'priority' => array('label' => 'Priority', 'type' => 'enum', 'values' => array('low', 'medium', 'high', 'urgent'))
            )
        );
    }

    /**
     * Condition operators (key => label)
     * @return array
     */
    public static function getOperators() {
        return array(
            'equals' => 'Equals',
            'not_equals' => 'Does not equal',
            'contains' => 'Contains',
            'not_contains' => 'Does not contain',
            'starts_with' => 'Starts with',
            'ends_with' => 'Ends with',
            'greater_than' => 'Greater than',
            'less_than' => 'Less than',
            'greater_than_or_equal' => 'Greater than or equal',
            'less_than_or_equal' => 'Less than or equal',
            'is_empty' => 'Is empty',
            'is_not_empty' => 'Is not empty'
        );
    }

    /**
     * Entity type a workflow operates on ('' = none, e.g. a scheduled reminder without records)
     * @param array $workflow Workflow row
     * @return string
     */
    public static function entityTypeFor($workflow) {
        $trigger = isset($workflow['trigger_type']) ? (string)$workflow['trigger_type'] : '';
        if ($trigger === 'scheduled') {
            $config = self::decodeJson(isset($workflow['trigger_config']) ? $workflow['trigger_config'] : null);
            $entity = isset($config['entity']) ? (string)$config['entity'] : '';
            return in_array($entity, array('lead', 'task', 'deal'), true) ? $entity : '';
        }
        $prefix = strstr($trigger, '_', true);
        return ($prefix !== false && isset(self::$entities[$prefix])) ? $prefix : '';
    }

    /**
     * Relative app link for an entity
     * @param string $entityType Entity type
     * @param int $id Entity ID
     * @return string|null
     */
    public static function entityLink($entityType, $id) {
        if (!isset(self::$entities[$entityType]) || empty($id)) {
            return null;
        }
        return self::$entities[$entityType]['link'] . (int)$id;
    }

    /**
     * json_decode that always returns an array
     * @param mixed $json JSON text
     * @return array
     */
    public static function decodeJson($json) {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return array();
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

    // ==================================================================
    // Entry points
    // ==================================================================

    /**
     * Run every active workflow of the tenant whose trigger matches
     * @param int $tenantId Tenant ID
     * @param string $triggerType Workflow trigger type (see getWorkflowTriggerMap())
     * @param array $data Entity row
     * @return array ['executed' => n, 'workflows' => [...]]
     */
    public function trigger($tenantId, $triggerType, $data) {
        $results = array();
        $workflows = $this->workflowModel->getByTrigger($tenantId, $triggerType);

        foreach ($workflows as $workflow) {
            try {
                $context = $this->buildContext($workflow, is_array($data) ? $data : array());
                if (!$this->conditionsMatch($workflow, $context)) {
                    continue;
                }
                $executionId = $this->executeWorkflow($workflow, $context);
                $results[] = array(
                    'workflow_id' => (int)$workflow['id'],
                    'workflow_name' => $workflow['name'],
                    'execution_id' => $executionId
                );
            } catch (Throwable $e) {
                error_log('Workflow #' . $workflow['id'] . ' trigger error: ' . $e->getMessage());
            }
        }

        return array('executed' => count($results), 'workflows' => $results);
    }

    /**
     * Execute a workflow's actions for one record.
     * Conditions are NOT checked here (callers decide; see trigger()).
     * @param array $workflow Workflow row (must include tenant_id)
     * @param array $data Entity row or context from buildContext()
     * @param array $options ignore_delays (bool): run delayed actions now;
     *                       dry_run (bool): validate/preview only, no side effects
     * @return int|false Execution ID
     */
    public function executeWorkflow($workflow, $data, $options = array()) {
        $ignoreDelays = !empty($options['ignore_delays']);
        $dryRun = !empty($options['dry_run']);

        $context = isset($data['__context']) ? $data : $this->buildContext($workflow, $data);
        if ($dryRun || $ignoreDelays) {
            $context['_test_run'] = true;
        }

        $executionId = $this->createExecution($workflow['id'], $context);
        if (!$executionId) {
            return false;
        }

        try {
            $actions = $this->workflowModel->getActions($workflow['id']);
            foreach ($actions as $action) {
                $delay = (int)$action['delay_minutes'];
                if ($delay > 0 && !$ignoreDelays && !$dryRun) {
                    $this->logScheduled($executionId, $action['id'], $delay);
                    continue;
                }
                $this->runLoggedAction($executionId, $action, $workflow, $context, $dryRun);
            }
        } catch (Throwable $e) {
            error_log('Workflow #' . $workflow['id'] . ' execution #' . $executionId . ' error: ' . $e->getMessage());
            $this->setExecutionError($executionId, $e->getMessage());
        }

        $this->finalizeExecution($executionId);
        return $executionId;
    }

    /**
     * Run delayed actions whose scheduled_for time has passed (called by cron)
     * @param int $limit Max actions per run
     * @return array Summary
     */
    public function runDueActions($limit = 200) {
        $summary = array('due' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0, 'recovered' => 0);

        $summary['recovered'] = $this->recoverStuckActions();

        $stmt = $this->conn->prepare(
            "SELECT l.id AS log_id, l.execution_id, a.id, a.workflow_id, a.action_type, a.action_config,
                    a.execution_order, a.delay_minutes, we.trigger_data,
                    w.tenant_id, w.name AS workflow_name, w.status AS workflow_status, w.created_by,
                    w.trigger_type, w.trigger_config
             FROM workflow_action_logs l
             INNER JOIN workflow_actions a ON a.id = l.action_id
             INNER JOIN workflow_executions we ON we.id = l.execution_id
             INNER JOIN workflows w ON w.id = we.workflow_id
             WHERE l.status = 'scheduled' AND l.scheduled_for <= NOW()
             ORDER BY l.scheduled_for ASC, l.id ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            // Claim the log row so overlapping cron runs never execute it twice
            $claim = $this->conn->prepare(
                "UPDATE workflow_action_logs SET status = 'pending', started_at = NOW()
                 WHERE id = :id AND status = 'scheduled'"
            );
            $claim->bindValue(':id', (int)$row['log_id'], PDO::PARAM_INT);
            $claim->execute();
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $summary['due']++;

            $workflow = array(
                'id' => $row['workflow_id'],
                'tenant_id' => $row['tenant_id'],
                'name' => $row['workflow_name'],
                'created_by' => $row['created_by'],
                'trigger_type' => $row['trigger_type'],
                'trigger_config' => $row['trigger_config']
            );
            $action = array(
                'id' => $row['id'],
                'action_type' => $row['action_type'],
                'action_config' => $row['action_config'],
                'delay_minutes' => $row['delay_minutes']
            );

            try {
                if ($row['workflow_status'] !== 'active') {
                    $this->finishLog($row['log_id'], 'skipped', 'Workflow was deactivated before the delayed action was due');
                    $summary['skipped']++;
                } else {
                    $stored = self::decodeJson($row['trigger_data']);
                    $context = $this->refreshContext($workflow, $stored);
                    if ($context === null) {
                        $this->finishLog($row['log_id'], 'skipped', 'The record no longer exists');
                        $summary['skipped']++;
                    } else {
                        $status = $this->runAction($row['log_id'], $action, $workflow, $context, false);
                        $summary[$status === 'completed' ? 'completed' : 'failed']++;
                    }
                }
            } catch (Throwable $e) {
                $this->finishLog($row['log_id'], 'failed', null, $e->getMessage());
                $summary['failed']++;
            }

            $this->finalizeExecution($row['execution_id']);
        }

        return $summary;
    }

    /**
     * Run due 'scheduled' workflows against matching records (called by cron)
     * @return array Summary
     */
    public function runScheduledWorkflows() {
        $summary = array('workflows' => 0, 'records' => 0, 'executions' => 0);
        $workflows = $this->workflowModel->getDueScheduled(50);

        foreach ($workflows as $workflow) {
            // Mark first so a slow/failing run is not retried every minute
            $this->workflowModel->markRun($workflow['id'], $workflow['tenant_id']);
            $summary['workflows']++;

            try {
                $config = self::decodeJson($workflow['trigger_config']);
                $entityType = self::entityTypeFor($workflow);

                if ($entityType === '') {
                    // Record-less schedule (e.g. a daily reminder notification)
                    $context = $this->buildContext($workflow, array());
                    if ($this->conditionsMatch($workflow, $context) && $this->executeWorkflow($workflow, $context)) {
                        $summary['executions']++;
                    }
                    continue;
                }

                $records = $this->findScheduledRecords($workflow, $entityType, $config);
                foreach ($records as $record) {
                    $summary['records']++;
                    $context = $this->buildContext($workflow, $record);
                    if ($this->conditionsMatch($workflow, $context) && $this->executeWorkflow($workflow, $context)) {
                        $summary['executions']++;
                    }
                }
            } catch (Throwable $e) {
                error_log('Scheduled workflow #' . $workflow['id'] . ' failed: ' . $e->getMessage());
            }
        }

        return $summary;
    }

    // ==================================================================
    // Conditions
    // ==================================================================

    /**
     * Whether all conditions of a workflow match (invalid JSON = no match)
     * @param array $workflow Workflow row
     * @param array $data Context
     * @return bool
     */
    public function conditionsMatch($workflow, $data) {
        $explained = $this->explainConditions($workflow, $data);
        return $explained['matched'];
    }

    /**
     * Evaluate conditions one by one (used by the test run to show why a workflow did or did not match)
     * @param array $workflow Workflow row
     * @param array $data Context
     * @return array ['matched' => bool, 'conditions' => [[field, operator, value, actual, matched]], 'error' => string|null]
     */
    public function explainConditions($workflow, $data) {
        $result = array('matched' => true, 'conditions' => array(), 'error' => null);
        $raw = isset($workflow['conditions']) ? $workflow['conditions'] : null;
        if ($raw === null || $raw === '' || $raw === '[]' || $raw === 'null') {
            return $result;
        }

        $conditions = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($conditions)) {
            error_log('Workflow #' . (isset($workflow['id']) ? $workflow['id'] : '?') . ' has invalid conditions JSON');
            $result['matched'] = false;
            $result['error'] = 'Conditions could not be read (invalid JSON); the workflow will not run until they are saved again.';
            return $result;
        }

        foreach ($conditions as $condition) {
            if (!is_array($condition) || empty($condition['field'])) {
                continue;
            }
            $field = (string)$condition['field'];
            $operator = isset($condition['operator']) ? (string)$condition['operator'] : 'equals';
            $value = isset($condition['value']) && is_scalar($condition['value']) ? (string)$condition['value'] : '';
            $actual = array_key_exists($field, $data) ? $data[$field] : null;
            $matched = $this->evaluateCondition($actual, $operator, $value);

            $result['conditions'][] = array(
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
                'actual' => is_scalar($actual) ? (string)$actual : ($actual === null ? null : json_encode($actual)),
                'matched' => $matched
            );
            if (!$matched) {
                $result['matched'] = false;
            }
        }
        return $result;
    }

    /**
     * Evaluate one condition (null-safe, case-insensitive for strings, numeric when both sides are numbers)
     * @param mixed $actual Record value
     * @param string $operator Operator
     * @param string $expected Configured value
     * @return bool
     */
    private function evaluateCondition($actual, $operator, $expected) {
        if (is_bool($actual)) {
            $actual = $actual ? '1' : '0';
        }
        $a = is_scalar($actual) ? trim((string)$actual) : '';
        $b = trim((string)$expected);
        $numeric = is_numeric($a) && is_numeric($b);

        switch ($operator) {
            case 'equals':
                return $numeric ? (float)$a == (float)$b : strcasecmp($a, $b) === 0;
            case 'not_equals':
                return $numeric ? (float)$a != (float)$b : strcasecmp($a, $b) !== 0;
            case 'contains':
                return $b === '' || stripos($a, $b) !== false;
            case 'not_contains':
                return $b !== '' && stripos($a, $b) === false;
            case 'starts_with':
                return $b === '' || strncasecmp($a, $b, strlen($b)) === 0;
            case 'ends_with':
                return $b === '' || (strlen($a) >= strlen($b) && strcasecmp(substr($a, -strlen($b)), $b) === 0);
            case 'greater_than':
            case 'less_than':
            case 'greater_than_or_equal':
            case 'less_than_or_equal':
                if ($a === '' || $b === '') {
                    return false;
                }
                $cmp = $numeric ? (((float)$a > (float)$b) - ((float)$a < (float)$b)) : strcmp($a, $b);
                if ($operator === 'greater_than') {
                    return $cmp > 0;
                }
                if ($operator === 'less_than') {
                    return $cmp < 0;
                }
                if ($operator === 'greater_than_or_equal') {
                    return $cmp >= 0;
                }
                return $cmp <= 0;
            case 'is_empty':
                return $a === '';
            case 'is_not_empty':
                return $a !== '';
            default:
                return false;
        }
    }

    // ==================================================================
    // Context
    // ==================================================================

    /**
     * Build the execution context: the entity row plus entity_type/entity_id,
     * tenant_id (always the workflow's tenant) and convenience placeholders.
     * entity_type and entity_id are the first keys of the stored JSON; the
     * scheduler relies on that to avoid processing a record twice.
     * @param array $workflow Workflow row
     * @param array $data Entity row
     * @return array
     */
    public function buildContext($workflow, $data) {
        $entityType = self::entityTypeFor($workflow);
        $entityId = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : (isset($data['entity_id']) && is_numeric($data['entity_id']) ? (int)$data['entity_id'] : null);

        $context = array('entity_type' => $entityType, 'entity_id' => $entityId);
        foreach ($data as $key => $value) {
            if ($key === 'entity_type' || $key === 'entity_id' || $key === '__context') {
                continue;
            }
            $context[$key] = $value;
        }
        $context['tenant_id'] = (int)$workflow['tenant_id'];
        $context['workflow_id'] = (int)$workflow['id'];
        $context['workflow_name'] = isset($workflow['name']) ? (string)$workflow['name'] : '';

        $first = isset($context['first_name']) && is_scalar($context['first_name']) ? trim((string)$context['first_name']) : '';
        $last = isset($context['last_name']) && is_scalar($context['last_name']) ? trim((string)$context['last_name']) : '';
        if ($first !== '' || $last !== '') {
            $context['full_name'] = trim($first . ' ' . $last);
        }
        $link = self::entityLink($entityType, $entityId);
        $context['entity_link'] = $link !== null ? url($link) : '';
        $context['today'] = date('Y-m-d');
        $context['now'] = date('Y-m-d H:i');

        $assigneeColumn = isset(self::$entities[$entityType]) ? self::$entities[$entityType]['assignee'] : null;
        if ($assigneeColumn !== null && !isset($context['assigned_to']) && isset($context[$assigneeColumn])) {
            $context['assigned_to'] = $context[$assigneeColumn];
        }
        $this->addAssigneeToContext($context);

        $context['__context'] = true;
        return $context;
    }

    /**
     * Add assigned_user_name / assigned_user_email placeholders
     * @param array $context Context (by reference)
     */
    private function addAssigneeToContext(&$context) {
        $context['assigned_user_name'] = '';
        $context['assigned_user_email'] = '';
        if (!empty($context['assigned_to']) && is_numeric($context['assigned_to'])) {
            $user = $this->findTenantUser((int)$context['assigned_to'], (int)$context['tenant_id']);
            if ($user) {
                $context['assigned_user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                $context['assigned_user_email'] = (string)$user['email'];
            }
        }
    }

    /**
     * Rebuild a stored context with the current state of its record (delayed actions)
     * @param array $workflow Workflow row
     * @param array $stored Stored context
     * @return array|null Null when the record was deleted
     */
    private function refreshContext($workflow, $stored) {
        $entityType = isset($stored['entity_type']) ? (string)$stored['entity_type'] : '';
        $entityId = isset($stored['entity_id']) ? (int)$stored['entity_id'] : 0;
        // Derived keys are recomputed by buildContext() from the fresh row
        foreach (array('__context', 'full_name', 'entity_link', 'today', 'now', 'assigned_user_name',
                       'assigned_user_email', 'tenant_id', 'workflow_id', 'workflow_name') as $derived) {
            unset($stored[$derived]);
        }
        if ($entityType === 'property') {
            unset($stored['assigned_to']);
        }

        if ($entityType === '' || $entityId <= 0) {
            return $this->buildContext($workflow, $stored);
        }
        $row = $this->loadEntity($entityType, $entityId, $workflow['tenant_id']);
        if (!$row) {
            return null;
        }
        // Keep event-only keys (old_status, score, ...) but prefer fresh column values
        return $this->buildContext($workflow, array_merge($stored, $row));
    }

    /**
     * Load an entity row, tenant scoped
     * @param string $entityType Entity type
     * @param int $id ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function loadEntity($entityType, $id, $tenantId) {
        if (!isset(self::$entities[$entityType])) {
            return false;
        }
        $table = self::$entities[$entityType]['table'];
        $stmt = $this->conn->prepare("SELECT * FROM `{$table}` WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Most recent entity of a type for a tenant (default sample for test runs)
     * @param string $entityType Entity type
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function loadLatestEntity($entityType, $tenantId) {
        if (!isset(self::$entities[$entityType])) {
            return false;
        }
        $table = self::$entities[$entityType]['table'];
        $stmt = $this->conn->prepare("SELECT * FROM `{$table}` WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 1");
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Active user of the tenant
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    private function findTenantUser($userId, $tenantId) {
        $stmt = $this->conn->prepare(
            "SELECT id, first_name, last_name, email FROM users
             WHERE id = :id AND tenant_id = :tenant_id AND status = 'active' LIMIT 1"
        );
        $stmt->bindValue(':id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Resolve a configured user reference ("5", "{{assigned_to}}") to a tenant user
     * @param mixed $value Configured value
     * @param array $context Context
     * @return array|false
     */
    private function resolveUser($value, $context) {
        $resolved = trim($this->replacePlaceholders(is_scalar($value) ? (string)$value : '', $context));
        if ($resolved === '' || !ctype_digit($resolved)) {
            return false;
        }
        return $this->findTenantUser((int)$resolved, (int)$context['tenant_id']);
    }

    /**
     * Replace {{key}} placeholders with scalar context values. Unknown keys become ''.
     * @param mixed $text Template
     * @param array $data Context
     * @param bool $escapeHtml HTML-escape substituted values (for email bodies)
     * @return string
     */
    public function replacePlaceholders($text, $data, $escapeHtml = false) {
        if (!is_scalar($text)) {
            return '';
        }
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($m) use ($data, $escapeHtml) {
            $value = isset($data[$m[1]]) ? $data[$m[1]] : '';
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $value = is_scalar($value) ? (string)$value : '';
            return $escapeHtml ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
        }, (string)$text);
    }

    // ==================================================================
    // Scheduled trigger records
    // ==================================================================

    /**
     * Records matching a scheduled workflow's filters (tenant scoped, capped).
     * Unless trigger_config.repeat is true, records this workflow already ran
     * for are skipped.
     * Filters: status (lead status / task status / deal stage), older_than_days,
     * inactive_days, unassigned (leads), overdue (tasks/deals), due_within_hours (tasks).
     * @param array $workflow Workflow row
     * @param string $entityType lead|task|deal
     * @param array $config trigger_config
     * @return array
     */
    private function findScheduledRecords($workflow, $entityType, $config) {
        $table = self::$entities[$entityType]['table'];
        $where = array('x.tenant_id = :tenant_id');
        $params = array(':tenant_id' => (int)$workflow['tenant_id']);

        $status = isset($config['status']) && is_string($config['status']) ? $config['status'] : '';
        $statusColumn = $entityType === 'deal' ? 'stage' : 'status';
        $fields = self::getUpdatableFields();
        if ($status !== '' && in_array($status, $fields[$entityType][$statusColumn]['values'], true)) {
            $where[] = "x.`{$statusColumn}` = :status";
            $params[':status'] = $status;
        }

        $olderThan = isset($config['older_than_days']) ? (int)$config['older_than_days'] : 0;
        if ($olderThan > 0) {
            $where[] = 'x.created_at <= DATE_SUB(NOW(), INTERVAL ' . min($olderThan, 3650) . ' DAY)';
        }
        $inactive = isset($config['inactive_days']) ? (int)$config['inactive_days'] : 0;
        if ($inactive > 0) {
            $where[] = 'COALESCE(x.updated_at, x.created_at) <= DATE_SUB(NOW(), INTERVAL ' . min($inactive, 3650) . ' DAY)';
        }

        if ($entityType === 'lead' && !empty($config['unassigned'])) {
            $where[] = 'x.assigned_to IS NULL';
        }
        if ($entityType === 'task') {
            if (!empty($config['overdue'])) {
                $where[] = "x.due_date < NOW() AND x.status IN ('pending','in_progress')";
            }
            $dueWithin = isset($config['due_within_hours']) ? (int)$config['due_within_hours'] : 0;
            if ($dueWithin > 0) {
                $where[] = 'x.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ' . min($dueWithin, 8760) . " HOUR) AND x.status IN ('pending','in_progress')";
            }
        }
        if ($entityType === 'deal' && !empty($config['overdue'])) {
            $where[] = "x.expected_close_date < CURDATE() AND x.stage NOT IN ('closed_won','closed_lost')";
        }

        if (empty($config['repeat'])) {
            $where[] = "NOT EXISTS (SELECT 1 FROM workflow_executions we
                         WHERE we.workflow_id = :workflow_id
                         AND we.trigger_data LIKE CONCAT('{\"entity_type\":\"{$entityType}\",\"entity_id\":', x.id, ',%')
                         AND we.trigger_data NOT LIKE '%\"_test_run\":true%')";
            $params[':workflow_id'] = (int)$workflow['id'];
        }

        $sql = "SELECT x.* FROM `{$table}` x WHERE " . implode(' AND ', $where)
             . ' ORDER BY x.id ASC LIMIT ' . (int)self::SCHEDULED_RECORD_CAP;
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ==================================================================
    // Action execution + logging
    // ==================================================================

    /**
     * Create a log row and run an action now
     * @return string Final status
     */
    private function runLoggedAction($executionId, $action, $workflow, &$context, $dryRun) {
        $stmt = $this->conn->prepare(
            "INSERT INTO workflow_action_logs (execution_id, action_id, status, started_at)
             VALUES (:execution_id, :action_id, 'pending', NOW())"
        );
        $stmt->bindValue(':execution_id', (int)$executionId, PDO::PARAM_INT);
        $stmt->bindValue(':action_id', (int)$action['id'], PDO::PARAM_INT);
        $stmt->execute();
        $logId = (int)$this->conn->lastInsertId();

        return $this->runAction($logId, $action, $workflow, $context, $dryRun);
    }

    /**
     * Run an action and record the outcome on its log row. Never throws.
     * @param int $logId Log ID
     * @param array $action Action row
     * @param array $workflow Workflow row
     * @param array $context Context (by reference; actions may update it)
     * @param bool $dryRun Preview only
     * @return string completed|failed|skipped
     */
    private function runAction($logId, $action, $workflow, &$context, $dryRun) {
        try {
            $config = self::decodeJson($action['action_config']);
            if ($action['action_config'] !== '' && $action['action_config'] !== null && json_decode((string)$action['action_config'], true) === null) {
                throw new RuntimeException('Action configuration is not valid JSON');
            }

            switch ($action['action_type']) {
                case 'send_email':
                    $result = $this->actionSendEmail($config, $context, $dryRun);
                    break;
                case 'create_task':
                    $result = $this->actionCreateTask($config, $context, $workflow, $dryRun);
                    break;
                case 'update_field':
                    $result = $this->actionUpdateField($config, $context, $dryRun);
                    break;
                case 'assign_to_user':
                    $result = $this->actionAssignToUser($config, $context, $dryRun);
                    break;
                case 'add_tag':
                    $result = $this->actionAddTag($config, $context, $dryRun);
                    break;
                case 'send_webhook':
                    $result = $this->actionSendWebhook($config, $context, $dryRun);
                    break;
                case 'send_notification':
                    $result = $this->actionSendNotification($config, $context, $dryRun);
                    break;
                case 'create_deal':
                    $result = $this->actionCreateDeal($config, $context, $workflow, $dryRun);
                    break;
                case 'wait':
                    $this->finishLog($logId, 'skipped', 'Wait steps are not supported; use the action delay instead');
                    return 'skipped';
                default:
                    throw new RuntimeException('Unknown action type: ' . $action['action_type']);
            }

            $status = $dryRun ? 'skipped' : 'completed';
            $this->finishLog($logId, $status, ($dryRun ? 'Dry run: ' : '') . $result);
            return $status;
        } catch (Throwable $e) {
            error_log('Workflow #' . $workflow['id'] . ' action #' . $action['id'] . ' (' . $action['action_type'] . ') failed: ' . $e->getMessage());
            $this->finishLog($logId, 'failed', null, $e->getMessage());
            return 'failed';
        }
    }

    /** Send Email: config to, subject, body */
    private function actionSendEmail($config, $context, $dryRun) {
        $to = trim($this->replacePlaceholders(isset($config['to']) ? $config['to'] : '', $context));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid or empty recipient email "' . $to . '"');
        }
        $subject = trim(str_replace(array("\r", "\n"), ' ', $this->replacePlaceholders(isset($config['subject']) ? $config['subject'] : '', $context)));
        if ($subject === '') {
            $subject = (string)$context['workflow_name'];
        }
        $template = isset($config['body']) && is_scalar($config['body']) ? (string)$config['body'] : '';
        $body = $this->replacePlaceholders($template, $context, true);
        if (strip_tags($template) === $template) {
            $body = nl2br($body);
        }

        if ($dryRun) {
            return 'would email ' . $to . ' with subject "' . $subject . '"';
        }
        if (!sendEmail($to, $subject, $body)) {
            throw new RuntimeException('Email to ' . $to . ' could not be sent');
        }
        return 'Email sent to ' . $to;
    }

    /** Create Task: config title, description, assigned_to, due_in, priority */
    private function actionCreateTask($config, $context, $workflow, $dryRun) {
        $title = trim($this->replacePlaceholders(isset($config['title']) ? $config['title'] : '', $context));
        if ($title === '') {
            throw new RuntimeException('Task title is empty');
        }
        $title = mb_substr($title, 0, 255);
        $description = $this->replacePlaceholders(isset($config['description']) ? $config['description'] : '', $context);

        $assignee = null;
        $note = '';
        if (isset($config['assigned_to']) && $config['assigned_to'] !== '') {
            $user = $this->resolveUser($config['assigned_to'], $context);
            if ($user) {
                $assignee = (int)$user['id'];
            } else {
                $note = ' (assignee not found in this account; left unassigned)';
            }
        }

        $dueIn = isset($config['due_in']) ? $config['due_in'] : (isset($config['due_date_offset']) ? $config['due_date_offset'] : '');
        $dueDate = $this->resolveDueDate($dueIn);

        $priority = isset($config['priority']) && in_array($config['priority'], array('low', 'medium', 'high', 'urgent'), true) ? $config['priority'] : 'medium';
        $relatedType = in_array($context['entity_type'], array('lead', 'client', 'property', 'deal'), true) && !empty($context['entity_id']) ? $context['entity_type'] : null;
        $createdBy = !empty($workflow['created_by']) && $this->findTenantUser((int)$workflow['created_by'], (int)$context['tenant_id']) ? (int)$workflow['created_by'] : null;

        if ($dryRun) {
            return 'would create task "' . $title . '"' . ($assignee ? ' for user #' . $assignee : '') . ($dueDate ? ' due ' . $dueDate : '') . $note;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tasks (tenant_id, assigned_to, created_by, related_to_type, related_to_id, title, description, due_date, priority, status)
             VALUES (:tenant_id, :assigned_to, :created_by, :related_to_type, :related_to_id, :title, :description, :due_date, :priority, 'pending')"
        );
        $stmt->bindValue(':tenant_id', (int)$context['tenant_id'], PDO::PARAM_INT);
        $stmt->bindValue(':assigned_to', $assignee, $assignee === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':created_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':related_to_type', $relatedType, $relatedType === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':related_to_id', $relatedType === null ? null : (int)$context['entity_id'], $relatedType === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':description', $description === '' ? null : $description);
        $stmt->bindValue(':due_date', $dueDate);
        $stmt->bindValue(':priority', $priority);
        $stmt->execute();
        $taskId = (int)$this->conn->lastInsertId();

        $task = $this->loadEntity('task', $taskId, $context['tenant_id']);
        if ($task) {
            dispatchEvent((int)$context['tenant_id'], 'task.created', $task);
        }
        return 'Created task #' . $taskId . ' "' . $title . '"' . $note;
    }

    /**
     * Parse a relative due date: "+7 days", "2 hours", "30 minutes", "1 week", or a number of days
     * @param mixed $value Value
     * @return string|null Y-m-d H:i:s
     */
    private function resolveDueDate($value) {
        $value = is_scalar($value) ? strtolower(trim((string)$value)) : '';
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            $value = $value . ' days';
        }
        if (!preg_match('/^\+?\s*(\d{1,4})\s*(minute|min|hour|day|week|month)s?$/', $value, $m)) {
            throw new RuntimeException('Invalid due date "' . $value . '" (use e.g. "+3 days", "2 hours")');
        }
        $unit = $m[2] === 'min' ? 'minute' : $m[2];
        return date('Y-m-d H:i:s', strtotime('+' . (int)$m[1] . ' ' . $unit));
    }

    /** Update Field: config field, value (whitelisted per entity type) */
    private function actionUpdateField($config, &$context, $dryRun) {
        $entityType = $context['entity_type'];
        $fields = self::getUpdatableFields();
        $field = isset($config['field']) ? (string)$config['field'] : (isset($config['field_name']) ? (string)$config['field_name'] : '');
        if (!isset($fields[$entityType]) || empty($context['entity_id'])) {
            throw new RuntimeException('This trigger has no record whose fields can be updated');
        }
        if (!isset($fields[$entityType][$field])) {
            throw new RuntimeException('Field "' . $field . '" cannot be updated on a ' . $entityType);
        }
        $raw = isset($config['value']) ? $config['value'] : (isset($config['field_value']) ? $config['field_value'] : '');
        $value = $this->normalizeFieldValue($fields[$entityType][$field], trim($this->replacePlaceholders($raw, $context)), $field);

        $table = self::$entities[$entityType]['table'];
        if ($dryRun) {
            return 'would set ' . $table . '.' . $field . ' = "' . (string)$value . '" on #' . (int)$context['entity_id'];
        }

        $stmt = $this->conn->prepare("UPDATE `{$table}` SET `{$field}` = :value WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->bindValue(':value', $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', (int)$context['entity_id'], PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$context['tenant_id'], PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0 && !$this->loadEntity($entityType, $context['entity_id'], $context['tenant_id'])) {
            throw new RuntimeException(ucfirst($entityType) . ' #' . (int)$context['entity_id'] . ' not found');
        }
        $context[$field] = $value;
        return 'Set ' . $table . '.' . $field . ' = "' . (string)$value . '"';
    }

    /**
     * Validate/convert a value for a whitelisted field
     * @param array $def Field definition
     * @param string $value Value
     * @param string $field Field name
     * @return string|null
     */
    private function normalizeFieldValue($def, $value, $field) {
        switch ($def['type']) {
            case 'enum':
                if (!in_array($value, $def['values'], true)) {
                    throw new RuntimeException('Invalid value "' . $value . '" for ' . $field . ' (allowed: ' . implode(', ', $def['values']) . ')');
                }
                return $value;
            case 'number':
                if ($value === '') {
                    return null;
                }
                if (!is_numeric($value)) {
                    throw new RuntimeException($field . ' must be a number');
                }
                return (string)(float)$value;
            case 'int':
                if (!preg_match('/^-?\d+$/', $value) || (int)$value < $def['min'] || (int)$value > $def['max']) {
                    throw new RuntimeException($field . ' must be a whole number between ' . $def['min'] . ' and ' . $def['max']);
                }
                return (string)(int)$value;
            case 'date':
                if ($value === '') {
                    return null;
                }
                $ts = strtotime($value);
                if ($ts === false) {
                    throw new RuntimeException($field . ' must be a date (e.g. 2026-12-31 or "+30 days")');
                }
                return date('Y-m-d', $ts);
            default:
                return $value === '' ? null : mb_substr($value, 0, isset($def['max']) ? $def['max'] : 255);
        }
    }

    /** Assign to User: config user_id (tenant user, "{{assigned_to}}" allowed) */
    private function actionAssignToUser($config, &$context, $dryRun) {
        $entityType = $context['entity_type'];
        $column = isset(self::$entities[$entityType]) ? self::$entities[$entityType]['assignee'] : null;
        if ($column === null || empty($context['entity_id'])) {
            throw new RuntimeException('Records of this trigger cannot be assigned');
        }
        $user = $this->resolveUser(isset($config['user_id']) ? $config['user_id'] : '', $context);
        if (!$user) {
            throw new RuntimeException('User to assign is not an active user of this account');
        }
        $table = self::$entities[$entityType]['table'];
        $name = trim($user['first_name'] . ' ' . $user['last_name']);
        if ($dryRun) {
            return 'would assign ' . $entityType . ' #' . (int)$context['entity_id'] . ' to ' . $name;
        }

        $stmt = $this->conn->prepare("UPDATE `{$table}` SET `{$column}` = :user_id WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
        $stmt->bindValue(':id', (int)$context['entity_id'], PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$context['tenant_id'], PDO::PARAM_INT);
        $stmt->execute();

        $context[$column] = (int)$user['id'];
        $context['assigned_to'] = (int)$user['id'];
        $this->addAssigneeToContext($context);
        return 'Assigned ' . $entityType . ' #' . (int)$context['entity_id'] . ' to ' . $name;
    }

    /** Add Tag (leads): config tag */
    private function actionAddTag($config, &$context, $dryRun) {
        if ($context['entity_type'] !== 'lead' || empty($context['entity_id'])) {
            throw new RuntimeException('Tags can only be added to leads');
        }
        $tag = trim(str_replace(',', ' ', $this->replacePlaceholders(isset($config['tag']) ? $config['tag'] : '', $context)));
        if ($tag === '') {
            throw new RuntimeException('Tag is empty');
        }
        $tag = mb_substr($tag, 0, 50);

        $lead = $this->loadEntity('lead', $context['entity_id'], $context['tenant_id']);
        if (!$lead) {
            throw new RuntimeException('Lead #' . (int)$context['entity_id'] . ' not found');
        }
        $tags = array();
        foreach (explode(',', (string)$lead['tags']) as $existing) {
            $existing = trim($existing);
            if ($existing !== '') {
                $tags[mb_strtolower($existing)] = $existing;
            }
        }
        if (isset($tags[mb_strtolower($tag)])) {
            return 'Lead already tagged "' . $tag . '"';
        }
        $tags[mb_strtolower($tag)] = $tag;
        $newTags = implode(', ', $tags);
        if (strlen($newTags) > 500) {
            throw new RuntimeException('Lead tags would exceed 500 characters');
        }
        if ($dryRun) {
            return 'would tag lead #' . (int)$context['entity_id'] . ' "' . $tag . '"';
        }

        $stmt = $this->conn->prepare("UPDATE leads SET tags = :tags WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->bindValue(':tags', $newTags);
        $stmt->bindValue(':id', (int)$context['entity_id'], PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$context['tenant_id'], PDO::PARAM_INT);
        $stmt->execute();
        $context['tags'] = $newTags;
        return 'Tagged lead #' . (int)$context['entity_id'] . ' "' . $tag . '"';
    }

    /** Send Webhook: config event (delivered to the tenant's webhooks subscribed to it) */
    private function actionSendWebhook($config, $context, $dryRun) {
        $event = isset($config['event']) ? trim((string)$config['event']) : (isset($config['event_type']) ? trim((string)$config['event_type']) : '');
        if ($event === '') {
            $event = 'workflow.triggered';
        }
        if (!preg_match('/^[a-z_]+\.[a-z_]+$/', $event)) {
            throw new RuntimeException('Invalid webhook event name "' . $event . '"');
        }
        if ($dryRun) {
            return 'would send webhook event ' . $event;
        }
        $payload = $context;
        unset($payload['__context'], $payload['_test_run']);

        require_once APP_PATH . '/helpers/WebhookService.php';
        $service = new WebhookService();
        if (!$service->trigger((int)$context['tenant_id'], $event, $payload)) {
            throw new RuntimeException('Webhook delivery for ' . $event . ' failed (failed deliveries are retried by cron)');
        }
        return 'Webhook event ' . $event . ' sent to subscribed endpoints';
    }

    /** Send Notification: config user_id, title, message, link */
    private function actionSendNotification($config, $context, $dryRun) {
        $user = $this->resolveUser(isset($config['user_id']) ? $config['user_id'] : '', $context);
        if (!$user) {
            throw new RuntimeException('Notification recipient is not an active user of this account');
        }
        $title = trim($this->replacePlaceholders(isset($config['title']) ? $config['title'] : '', $context));
        if ($title === '') {
            $title = (string)$context['workflow_name'];
        }
        $message = $this->replacePlaceholders(isset($config['message']) ? $config['message'] : '', $context);

        $link = trim($this->replacePlaceholders(isset($config['link']) ? $config['link'] : '', $context));
        if ($link === '') {
            $link = self::entityLink($context['entity_type'], $context['entity_id']);
        } elseif (!preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-\.]+)*(\?[A-Za-z0-9_\-=&%\.]*)?$#', ltrim($link, '/'))) {
            throw new RuntimeException('Notification link must be an app path such as leads/view/5');
        } else {
            $link = ltrim($link, '/');
        }

        $name = trim($user['first_name'] . ' ' . $user['last_name']);
        if ($dryRun) {
            return 'would notify ' . $name . ': "' . $title . '"';
        }
        $id = createNotification((int)$context['tenant_id'], (int)$user['id'], $title, $message, $link, 'workflow');
        if (!$id) {
            throw new RuntimeException('Notification could not be saved');
        }
        return 'Notified ' . $name . ' (notification #' . $id . ')';
    }

    /** Create Deal: config title, deal_value, stage, assigned_to */
    private function actionCreateDeal($config, $context, $workflow, $dryRun) {
        $title = trim($this->replacePlaceholders(isset($config['title']) ? $config['title'] : '', $context));
        if ($title === '') {
            throw new RuntimeException('Deal title is empty');
        }
        $title = mb_substr($title, 0, 255);
        $valueRaw = trim($this->replacePlaceholders(isset($config['deal_value']) ? $config['deal_value'] : '', $context));
        if ($valueRaw !== '' && !is_numeric($valueRaw)) {
            throw new RuntimeException('Deal value must be a number');
        }
        $stages = array('lead', 'qualified', 'viewing', 'offer', 'negotiation', 'contract');
        $stage = isset($config['stage']) && in_array($config['stage'], $stages, true) ? $config['stage'] : 'lead';

        $assignee = null;
        if (isset($config['assigned_to']) && $config['assigned_to'] !== '') {
            $user = $this->resolveUser($config['assigned_to'], $context);
            $assignee = $user ? (int)$user['id'] : null;
        }
        $clientId = $context['entity_type'] === 'client' ? (int)$context['entity_id'] : (isset($context['client_id']) && is_numeric($context['client_id']) ? (int)$context['client_id'] : null);
        $propertyId = $context['entity_type'] === 'property' ? (int)$context['entity_id'] : (isset($context['property_id']) && is_numeric($context['property_id']) ? (int)$context['property_id'] : null);
        $source = isset(self::$entities[$context['entity_type']]) && !empty($context['entity_id'])
            ? ' from ' . $context['entity_type'] . ' #' . (int)$context['entity_id'] : '';

        if ($dryRun) {
            return 'would create deal "' . $title . '" (' . $stage . ')';
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO deals (tenant_id, client_id, property_id, assigned_to, title, deal_value, stage, notes)
             VALUES (:tenant_id, :client_id, :property_id, :assigned_to, :title, :deal_value, :stage, :notes)"
        );
        $stmt->bindValue(':tenant_id', (int)$context['tenant_id'], PDO::PARAM_INT);
        $stmt->bindValue(':client_id', $clientId ?: null, $clientId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':property_id', $propertyId ?: null, $propertyId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':assigned_to', $assignee, $assignee === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':deal_value', $valueRaw === '' ? null : (string)(float)$valueRaw, $valueRaw === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':stage', $stage);
        $stmt->bindValue(':notes', 'Created by workflow "' . $workflow['name'] . '"' . $source);
        $stmt->execute();
        $dealId = (int)$this->conn->lastInsertId();

        $deal = $this->loadEntity('deal', $dealId, $context['tenant_id']);
        if ($deal) {
            dispatchEvent((int)$context['tenant_id'], 'deal.created', $deal);
        }
        return 'Created deal #' . $dealId . ' "' . $title . '"';
    }

    // ==================================================================
    // Persistence helpers
    // ==================================================================

    /**
     * Create a running execution row
     * @param int $workflowId Workflow ID
     * @param array $context Context
     * @return int|false
     */
    private function createExecution($workflowId, $context) {
        unset($context['__context']);
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $stmt = $this->conn->prepare(
            "INSERT INTO workflow_executions (workflow_id, trigger_data, status, started_at)
             VALUES (:workflow_id, :trigger_data, 'running', NOW())"
        );
        $stmt->bindValue(':workflow_id', (int)$workflowId, PDO::PARAM_INT);
        $stmt->bindValue(':trigger_data', $json === false ? '{}' : $json);
        $stmt->execute();
        $id = (int)$this->conn->lastInsertId();
        return $id > 0 ? $id : false;
    }

    /**
     * Record an execution-level error message
     */
    private function setExecutionError($executionId, $message) {
        $stmt = $this->conn->prepare("UPDATE workflow_executions SET error_message = :msg WHERE id = :id");
        $stmt->bindValue(':msg', mb_substr((string)$message, 0, 2000));
        $stmt->bindValue(':id', (int)$executionId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Set the execution's final status once no action is pending/scheduled:
     * failed if any action failed, otherwise completed.
     * @param int $executionId Execution ID
     */
    private function finalizeExecution($executionId) {
        $stmt = $this->conn->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status IN ('scheduled','pending') THEN 1 ELSE 0 END), 0) AS open_count,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count,
                COUNT(*) AS total
             FROM workflow_action_logs WHERE execution_id = :id"
        );
        $stmt->bindValue(':id', (int)$executionId, PDO::PARAM_INT);
        $stmt->execute();
        $counts = $stmt->fetch();

        if ((int)$counts['open_count'] > 0) {
            return; // still waiting for delayed actions
        }

        $execStmt = $this->conn->prepare("SELECT error_message FROM workflow_executions WHERE id = :id");
        $execStmt->bindValue(':id', (int)$executionId, PDO::PARAM_INT);
        $execStmt->execute();
        $existingError = (string)$execStmt->fetchColumn();

        $failed = (int)$counts['failed_count'];
        $status = ($failed > 0 || $existingError !== '') ? 'failed' : 'completed';
        $message = $existingError !== '' ? $existingError : ($failed > 0 ? $failed . ' of ' . (int)$counts['total'] . ' action(s) failed' : null);

        $update = $this->conn->prepare(
            "UPDATE workflow_executions SET status = :status, error_message = :msg, completed_at = NOW()
             WHERE id = :id AND status IN ('pending','running')"
        );
        $update->bindValue(':status', $status);
        $update->bindValue(':msg', $message, $message === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $update->bindValue(':id', (int)$executionId, PDO::PARAM_INT);
        $update->execute();
    }

    /**
     * Record a delayed action (status scheduled, due trigger time + delay)
     */
    private function logScheduled($executionId, $actionId, $delayMinutes) {
        $stmt = $this->conn->prepare(
            "INSERT INTO workflow_action_logs (execution_id, action_id, status, scheduled_for, result)
             VALUES (:execution_id, :action_id, 'scheduled', DATE_ADD(NOW(), INTERVAL :delay MINUTE), :result)"
        );
        $stmt->bindValue(':execution_id', (int)$executionId, PDO::PARAM_INT);
        $stmt->bindValue(':action_id', (int)$actionId, PDO::PARAM_INT);
        $stmt->bindValue(':delay', (int)$delayMinutes, PDO::PARAM_INT);
        $stmt->bindValue(':result', 'Scheduled to run in ' . (int)$delayMinutes . ' minute(s)');
        return $stmt->execute();
    }

    /**
     * Store an action outcome
     */
    private function finishLog($logId, $status, $result, $error = null) {
        $stmt = $this->conn->prepare(
            "UPDATE workflow_action_logs
             SET status = :status, result = :result, error_message = :error,
                 started_at = COALESCE(started_at, NOW()), completed_at = NOW(), executed_at = NOW()
             WHERE id = :id"
        );
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':result', $result === null ? null : mb_substr((string)$result, 0, 5000), $result === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':error', $error === null ? null : mb_substr((string)$error, 0, 2000), $error === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', (int)$logId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Fail actions left 'pending' for over 15 minutes (process crashed mid-run) and close their executions
     * @return int Number recovered
     */
    private function recoverStuckActions() {
        $stmt = $this->conn->query(
            "SELECT id, execution_id FROM workflow_action_logs
             WHERE status = 'pending' AND started_at IS NOT NULL AND started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
             LIMIT 100"
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $this->finishLog($row['id'], 'failed', null, 'Action was interrupted before it finished');
            $this->finalizeExecution($row['execution_id']);
        }
        return count($rows);
    }
}

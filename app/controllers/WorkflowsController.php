<?php
// FILE: /app/controllers/WorkflowsController.php

/**
 * SplashEstate CRM - Workflows Controller
 * Create, edit, test and monitor automated workflows (permission: workflows.manage)
 *
 * Routes:
 *   GET  workflows/index             list (filters: status, trigger)
 *   GET  workflows/create            form
 *   POST workflows/store             create
 *   GET  workflows/edit/{id}         form + test run
 *   POST workflows/update/{id}       save
 *   POST workflows/delete/{id}       delete
 *   POST workflows/toggle/{id}       activate / deactivate
 *   GET  workflows/executions/{id}   run history of a workflow
 *   GET  workflows/execution/{id}    one execution with its action logs
 *   POST workflows/test/{id}         run now against a sample record
 */

require_once APP_PATH . '/models/Workflow.php';
require_once APP_PATH . '/helpers/WorkflowEngine.php';

class WorkflowsController extends Controller {

    /** Max actions / conditions per workflow */
    const MAX_ACTIONS = 20;
    const MAX_CONDITIONS = 20;
    /** Max action delay: 30 days */
    const MAX_DELAY_MINUTES = 43200;

    /** @var Workflow */
    private $workflowModel;

    /** @var WorkflowEngine */
    private $engine;

    public function __construct() {
        $this->requirePermission('workflows.manage');
        if (!$this->getTenantId()) {
            $this->setFlash('error', 'Workflows are managed inside a tenant account.');
            $this->redirect('dashboard/index');
        }
        $this->workflowModel = new Workflow();
        $this->engine = new WorkflowEngine();
    }

    /**
     * List workflows
     */
    public function index() {
        $tenantId = $this->getTenantId();
        $filters = array(
            'status' => isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '',
            'trigger_type' => isset($_GET['trigger']) && is_string($_GET['trigger']) ? $_GET['trigger'] : ''
        );
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = 20;

        $total = $this->workflowModel->count($tenantId, $filters);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);

        $this->view('workflows/index', array(
            'title' => 'Workflows',
            'workflows' => $this->workflowModel->getAll($tenantId, $page, $perPage, $filters),
            'triggerTypes' => $this->getTriggerTypes(),
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total
        ));
    }

    /**
     * New workflow form
     */
    public function create() {
        $this->renderForm('create', $this->emptyWorkflow(), array(), array());
    }

    /**
     * Create a workflow
     */
    public function store() {
        $this->requirePostWithCsrf('workflows/create');
        $tenantId = $this->getTenantId();

        list($data, $actions, $errors) = $this->collectInput();
        if (!empty($errors)) {
            $this->renderForm('create', $this->workflowFromInput($data), $this->actionsForForm($actions), $errors);
            return;
        }

        $data['tenant_id'] = $tenantId;
        $data['created_by'] = $this->getUserId();
        $id = $this->workflowModel->createWithActions($data, $actions);

        if (!$id) {
            $this->renderForm('create', $this->workflowFromInput($data), $this->actionsForForm($actions), array('The workflow could not be saved. Please try again.'));
            return;
        }

        logActivity($this->getUserId(), $tenantId, 'created', 'workflow', $id);
        $this->setFlash('success', 'Workflow created.');
        $this->redirect('workflows/edit/' . $id);
    }

    /**
     * Edit form
     * @param int $id Workflow ID
     */
    public function edit($id = null) {
        $workflow = $this->findWorkflowOrRedirect($id);
        $workflow['trigger_config'] = WorkflowEngine::decodeJson($workflow['trigger_config']);
        $workflow['conditions'] = WorkflowEngine::decodeJson($workflow['conditions']);

        $actions = array();
        foreach ($this->workflowModel->getActions($workflow['id']) as $action) {
            $actions[] = array(
                'action_type' => $action['action_type'],
                'action_config' => WorkflowEngine::decodeJson($action['action_config']),
                'delay_minutes' => (int)$action['delay_minutes']
            );
        }

        $this->renderForm('edit', $workflow, $actions, array(), $this->workflowModel->getStatistics($workflow['id']));
    }

    /**
     * Save changes
     * @param int $id Workflow ID
     */
    public function update($id = null) {
        $workflow = $this->findWorkflowOrRedirect($id);
        $this->requirePostWithCsrf('workflows/edit/' . (int)$workflow['id']);
        $tenantId = $this->getTenantId();

        list($data, $actions, $errors) = $this->collectInput();
        if (!empty($errors)) {
            $form = $this->workflowFromInput($data);
            $form['id'] = $workflow['id'];
            $this->renderForm('edit', $form, $this->actionsForForm($actions), $errors, $this->workflowModel->getStatistics($workflow['id']));
            return;
        }

        // A schedule change should take effect from now
        if ($data['trigger_type'] !== $workflow['trigger_type'] || $data['trigger_config'] !== $workflow['trigger_config']) {
            $data['last_run_at'] = null;
        }

        if ($this->workflowModel->updateWithActions($workflow['id'], $tenantId, $data, $actions)) {
            logActivity($this->getUserId(), $tenantId, 'updated', 'workflow', $workflow['id']);
            $this->setFlash('success', 'Workflow saved.');
        } else {
            $this->setFlash('error', 'The workflow could not be saved. Please try again.');
        }
        $this->redirect('workflows/edit/' . (int)$workflow['id']);
    }

    /**
     * Delete a workflow (its actions and history are removed by FK cascade)
     * @param int $id Workflow ID
     */
    public function delete($id = null) {
        $this->requirePostWithCsrf('workflows/index');
        $workflow = $this->findWorkflowOrRedirect($id);

        if ($this->workflowModel->delete($workflow['id'], $this->getTenantId())) {
            logActivity($this->getUserId(), $this->getTenantId(), 'deleted', 'workflow', $workflow['id']);
            $this->setFlash('success', 'Workflow "' . $workflow['name'] . '" deleted.');
        } else {
            $this->setFlash('error', 'Failed to delete the workflow.');
        }
        $this->redirect('workflows/index');
    }

    /**
     * Activate / deactivate
     * @param int $id Workflow ID
     */
    public function toggle($id = null) {
        $this->requirePostWithCsrf('workflows/index');
        $workflow = $this->findWorkflowOrRedirect($id);
        $newStatus = $workflow['status'] === 'active' ? 'inactive' : 'active';

        if ($this->workflowModel->update($workflow['id'], $this->getTenantId(), array('status' => $newStatus))) {
            $this->setFlash('success', 'Workflow "' . $workflow['name'] . '" is now ' . $newStatus . '.');
        } else {
            $this->setFlash('error', 'Failed to update the workflow status.');
        }

        $back = isset($_POST['return']) && $_POST['return'] === 'edit' ? 'workflows/edit/' . (int)$workflow['id'] : 'workflows/index';
        $this->redirect($back);
    }

    /**
     * Execution history of a workflow
     * @param int $id Workflow ID
     */
    public function executions($id = null) {
        $workflow = $this->findWorkflowOrRedirect($id);
        $perPage = 20;
        $total = $this->workflowModel->countExecutions($workflow['id']);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = isset($_GET['page']) ? min(max(1, (int)$_GET['page']), $totalPages) : 1;

        $this->view('workflows/executions', array(
            'title' => 'Workflow history - ' . $workflow['name'],
            'workflow' => $workflow,
            'triggerTypes' => $this->getTriggerTypes(),
            'stats' => $this->workflowModel->getStatistics($workflow['id']),
            'executions' => $this->workflowModel->getExecutions($workflow['id'], $perPage, ($page - 1) * $perPage),
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total
        ));
    }

    /**
     * One execution with its action logs
     * @param int $id Execution ID
     */
    public function execution($id = null) {
        $execution = $this->workflowModel->getExecutionForTenant((int)$id, $this->getTenantId());
        if (!$execution) {
            $this->setFlash('error', 'Execution not found.');
            $this->redirect('workflows/index');
        }
        $workflow = $this->workflowModel->getById($execution['workflow_id'], $this->getTenantId());

        $triggerData = WorkflowEngine::decodeJson($execution['trigger_data']);
        $logs = $this->workflowModel->getActionLogs($execution['id']);

        $this->view('workflows/execution_detail', array(
            'title' => 'Execution #' . (int)$execution['id'],
            'workflow' => $workflow,
            'execution' => $execution,
            'triggerData' => $triggerData,
            'actionLogs' => $logs,
            'actionTypes' => $this->getActionTypes(),
            'triggerTypes' => $this->getTriggerTypes()
        ));
    }

    /**
     * Run a workflow now against a sample record (delays ignored).
     * POST: sample_id (optional; default = newest record of the trigger's entity),
     *       dry_run (1 = preview only, nothing is changed)
     * @param int $id Workflow ID
     */
    public function test($id = null) {
        $workflow = $this->findWorkflowOrRedirect($id);
        $this->requirePostWithCsrf('workflows/edit/' . (int)$workflow['id']);
        $tenantId = $this->getTenantId();
        $dryRun = !empty($_POST['dry_run']);
        $entityType = WorkflowEngine::entityTypeFor($workflow);

        $record = false;
        $sampleId = isset($_POST['sample_id']) && is_string($_POST['sample_id']) ? trim($_POST['sample_id']) : '';
        if ($entityType !== '') {
            if ($sampleId !== '') {
                $record = ctype_digit($sampleId) ? $this->engine->loadEntity($entityType, (int)$sampleId, $tenantId) : false;
                if (!$record) {
                    $this->testFailed($workflow, ucfirst($entityType) . ' #' . $sampleId . ' was not found in your account.');
                }
            } else {
                $record = $this->engine->loadLatestEntity($entityType, $tenantId);
            }
        }
        if (!$record) {
            $record = $this->sampleData($entityType);
        }

        $context = $this->engine->buildContext($workflow, $record);
        $check = $this->engine->explainConditions($workflow, $context);
        if (!$check['matched']) {
            $failed = array();
            foreach ($check['conditions'] as $condition) {
                if (!$condition['matched']) {
                    $failed[] = $condition['field'] . ' ' . str_replace('_', ' ', $condition['operator']) . ' "' . $condition['value'] . '" (actual: "' . (string)$condition['actual'] . '")';
                }
            }
            $message = $check['error'] !== null ? $check['error'] : 'Conditions not met for this record: ' . implode('; ', $failed) . '. No actions were run.';
            $this->testFailed($workflow, $message, 'warning');
        }

        $executionId = $this->engine->executeWorkflow($workflow, $context, array('ignore_delays' => true, 'dry_run' => $dryRun));
        if (!$executionId) {
            $this->testFailed($workflow, 'The test run could not be started.');
        }

        $execution = $this->workflowModel->getExecutionForTenant($executionId, $tenantId);
        if ($this->wantsJson()) {
            $this->jsonResponse(array(
                'success' => true,
                'execution' => $execution,
                'actions' => $this->workflowModel->getActionLogs($executionId)
            ));
        }

        $label = $dryRun ? 'Dry run' : 'Test run';
        $status = $execution ? $execution['status'] : 'unknown';
        $this->setFlash($status === 'failed' ? 'warning' : 'success', $label . ' finished with status "' . $status . '".');
        $this->redirect('workflows/execution/' . (int)$executionId);
    }

    // ==================================================================
    // Helpers (private: not routable)
    // ==================================================================

    /**
     * Report a test-run problem (flash + back to edit, or JSON)
     */
    private function testFailed($workflow, $message, $type = 'error') {
        if ($this->wantsJson()) {
            $this->jsonResponse(array('success' => false, 'message' => $message), 422);
        }
        $this->setFlash($type, $message);
        $this->redirect('workflows/edit/' . (int)$workflow['id']);
    }

    /**
     * Synthetic record for test runs when the tenant has no record of that type
     * @param string $entityType Entity type
     * @return array
     */
    private function sampleData($entityType) {
        $userId = (int)$this->getUserId();
        switch ($entityType) {
            case 'lead':
                return array('first_name' => 'Sample', 'last_name' => 'Lead', 'email' => 'sample.lead@example.com', 'phone' => '555-0100', 'source' => 'Website', 'status' => 'new', 'interest_type' => 'buy', 'budget_min' => 200000, 'budget_max' => 400000, 'assigned_to' => $userId);
            case 'client':
                return array('first_name' => 'Sample', 'last_name' => 'Client', 'email' => 'sample.client@example.com', 'client_type' => 'buyer', 'status' => 'active');
            case 'property':
                return array('title' => 'Sample Property', 'price' => 350000, 'city' => 'Springfield', 'status' => 'available', 'listing_agent_id' => $userId);
            case 'deal':
                return array('title' => 'Sample Deal', 'deal_value' => 350000, 'stage' => 'offer', 'probability' => 50, 'assigned_to' => $userId);
            case 'task':
                return array('title' => 'Sample Task', 'priority' => 'medium', 'status' => 'pending', 'assigned_to' => $userId);
            default:
                return array();
        }
    }

    /**
     * Load a workflow of the current tenant or redirect to the list
     * @param mixed $id Workflow ID
     * @return array
     */
    private function findWorkflowOrRedirect($id) {
        $workflow = ($id !== null && ctype_digit((string)$id)) ? $this->workflowModel->getById((int)$id, $this->getTenantId()) : false;
        if (!$workflow) {
            if ($this->wantsJson()) {
                $this->jsonResponse(array('success' => false, 'message' => 'Workflow not found'), 404);
            }
            $this->setFlash('error', 'Workflow not found.');
            $this->redirect('workflows/index');
        }
        return $workflow;
    }

    /**
     * Render create/edit form
     */
    private function renderForm($mode, $workflow, $actions, $errors, $stats = null) {
        $entityType = WorkflowEngine::entityTypeFor(array(
            'trigger_type' => $workflow['trigger_type'],
            'trigger_config' => $workflow['trigger_config']
        ));

        require_once APP_PATH . '/models/Webhook.php';
        $webhookModel = new Webhook();
        $webhookEvents = array('workflow.triggered' => 'Workflow triggered (custom)');
        foreach ($webhookModel->getAvailableEvents() as $event => $label) {
            if ($event !== '*') {
                $webhookEvents[$event] = $label;
            }
        }

        $this->view('workflows/' . $mode, array(
            'title' => $mode === 'create' ? 'Create Workflow' : 'Edit Workflow',
            'mode' => $mode,
            'workflow' => $workflow,
            'actions' => $actions,
            'errors' => $errors,
            'stats' => $stats,
            'entityType' => $entityType,
            'triggerTypes' => $this->getTriggerTypes(),
            'actionTypes' => $this->getActionTypes(),
            'operators' => WorkflowEngine::getOperators(),
            'updatableFields' => WorkflowEngine::getUpdatableFields(),
            'users' => $this->workflowModel->getTenantUsers($this->getTenantId()),
            'webhookEvents' => $webhookEvents
        ));
    }

    /**
     * Blank workflow for the create form
     * @return array
     */
    private function emptyWorkflow() {
        return array(
            'id' => null,
            'name' => '',
            'description' => '',
            'trigger_type' => 'lead_created',
            'trigger_config' => array(),
            'conditions' => array(),
            'status' => 'active',
            'execution_order' => 0
        );
    }

    /**
     * Workflow array for re-rendering the form from validated input
     * @param array $data Collected data
     * @return array
     */
    private function workflowFromInput($data) {
        $workflow = array_merge($this->emptyWorkflow(), $data);
        $workflow['trigger_config'] = WorkflowEngine::decodeJson($data['trigger_config']);
        $workflow['conditions'] = WorkflowEngine::decodeJson($data['conditions']);
        return $workflow;
    }

    /**
     * Actions in the shape the form expects
     * @param array $actions Collected actions
     * @return array
     */
    private function actionsForForm($actions) {
        $out = array();
        foreach ($actions as $action) {
            $out[] = array(
                'action_type' => $action['action_type'],
                'action_config' => $action['action_config'],
                'delay_minutes' => $action['delay_minutes']
            );
        }
        return $out;
    }

    /**
     * Read and validate the workflow form
     * @return array [workflow data, actions, errors]
     */
    private function collectInput() {
        $errors = array();
        $post = $_POST;

        $name = isset($post['name']) && is_string($post['name']) ? trim($post['name']) : '';
        $description = isset($post['description']) && is_string($post['description']) ? trim($post['description']) : '';
        $triggerType = isset($post['trigger_type']) && is_string($post['trigger_type']) ? $post['trigger_type'] : '';
        $status = isset($post['status']) && $post['status'] === 'inactive' ? 'inactive' : 'active';
        $order = isset($post['execution_order']) && is_numeric($post['execution_order']) ? max(0, min(9999, (int)$post['execution_order'])) : 0;

        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'Name must be 255 characters or fewer.';
        }
        if (!array_key_exists($triggerType, $this->getTriggerTypes())) {
            $errors[] = 'Please choose a valid trigger.';
            $triggerType = 'lead_created';
        }

        // Trigger configuration (scheduled workflows only)
        $triggerConfig = null;
        if ($triggerType === 'scheduled') {
            $raw = isset($post['trigger_config']) && is_array($post['trigger_config']) ? $post['trigger_config'] : array();
            $config = array(
                'frequency' => isset($raw['frequency']) && in_array($raw['frequency'], array('hourly', 'daily', 'weekly'), true) ? $raw['frequency'] : 'daily',
                'entity' => isset($raw['entity']) && in_array($raw['entity'], array('lead', 'task', 'deal'), true) ? $raw['entity'] : ''
            );
            $fields = WorkflowEngine::getUpdatableFields();
            if ($config['entity'] !== '') {
                $statusColumn = $config['entity'] === 'deal' ? 'stage' : 'status';
                $statusValue = isset($raw['status']) && is_string($raw['status']) ? $raw['status'] : '';
                if ($statusValue !== '') {
                    if (in_array($statusValue, $fields[$config['entity']][$statusColumn]['values'], true)) {
                        $config['status'] = $statusValue;
                    } else {
                        $errors[] = 'Invalid ' . $statusColumn . ' filter for the schedule.';
                    }
                }
                foreach (array('older_than_days' => 3650, 'inactive_days' => 3650, 'due_within_hours' => 8760) as $key => $max) {
                    if (isset($raw[$key]) && $raw[$key] !== '') {
                        if (!is_scalar($raw[$key]) || !ctype_digit((string)$raw[$key]) || (int)$raw[$key] < 1 || (int)$raw[$key] > $max) {
                            $errors[] = 'Schedule filter "' . str_replace('_', ' ', $key) . '" must be a whole number between 1 and ' . $max . '.';
                        } else {
                            $config[$key] = (int)$raw[$key];
                        }
                    }
                }
                foreach (array('overdue', 'unassigned', 'repeat') as $flag) {
                    if (!empty($raw[$flag])) {
                        $config[$flag] = true;
                    }
                }
            }
            $triggerConfig = json_encode($config);
        }

        // Conditions
        $conditions = array();
        $operators = WorkflowEngine::getOperators();
        $rawConditions = isset($post['conditions']) && is_array($post['conditions']) ? $post['conditions'] : array();
        foreach ($rawConditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $field = isset($condition['field']) && is_string($condition['field']) ? trim($condition['field']) : '';
            if ($field === '') {
                continue;
            }
            $operator = isset($condition['operator']) && is_string($condition['operator']) ? $condition['operator'] : '';
            $value = isset($condition['value']) && is_string($condition['value']) ? trim($condition['value']) : '';
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $field)) {
                $errors[] = 'Condition field "' . $field . '" is not a valid field name.';
                continue;
            }
            if (!isset($operators[$operator])) {
                $errors[] = 'Condition on "' . $field . '" has an invalid operator.';
                continue;
            }
            $conditions[] = array('field' => $field, 'operator' => $operator, 'value' => mb_substr($value, 0, 255));
        }
        if (count($conditions) > self::MAX_CONDITIONS) {
            $errors[] = 'A workflow can have at most ' . self::MAX_CONDITIONS . ' conditions.';
        }

        // Actions
        $entityType = WorkflowEngine::entityTypeFor(array('trigger_type' => $triggerType, 'trigger_config' => $triggerConfig));
        $actions = array();
        $rawActions = isset($post['actions']) && is_array($post['actions']) ? array_values($post['actions']) : array();
        foreach ($rawActions as $i => $rawAction) {
            if (!is_array($rawAction)) {
                continue;
            }
            $actionErrors = array();
            $action = $this->collectAction($rawAction, $entityType, $actionErrors);
            if ($action === null) {
                foreach ($actionErrors as $error) {
                    $errors[] = 'Action ' . ($i + 1) . ': ' . $error;
                }
                continue;
            }
            foreach ($actionErrors as $error) {
                $errors[] = 'Action ' . ($i + 1) . ' (' . $this->actionLabel($action['action_type']) . '): ' . $error;
            }
            $action['execution_order'] = count($actions);
            $actions[] = $action;
        }
        if (empty($actions)) {
            $errors[] = 'Add at least one action.';
        } elseif (count($actions) > self::MAX_ACTIONS) {
            $errors[] = 'A workflow can have at most ' . self::MAX_ACTIONS . ' actions.';
        }

        $data = array(
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'trigger_type' => $triggerType,
            'trigger_config' => $triggerConfig,
            'conditions' => empty($conditions) ? null : json_encode($conditions),
            'status' => $status,
            'execution_order' => $order
        );

        return array($data, $actions, $errors);
    }

    /**
     * Validate one posted action
     * @param array $raw Posted action (type, delay, config[])
     * @param string $entityType Trigger entity type
     * @param array $errors Errors (by reference)
     * @return array|null Null when the row is empty or the type is unknown
     */
    private function collectAction($raw, $entityType, &$errors) {
        $type = isset($raw['type']) && is_string($raw['type']) ? $raw['type'] : '';
        $types = $this->getActionTypes();
        if ($type === '') {
            return null;
        }
        if (!isset($types[$type])) {
            $errors[] = 'unknown action type "' . $type . '" was removed' . ($type === 'wait' ? ' (use the action delay instead)' : '');
            return null;
        }

        $delay = isset($raw['delay']) && is_scalar($raw['delay']) && $raw['delay'] !== '' ? trim((string)$raw['delay']) : '0';
        if (!ctype_digit((string)$delay) || (int)$delay > self::MAX_DELAY_MINUTES) {
            $errors[] = 'delay must be a whole number of minutes between 0 and ' . self::MAX_DELAY_MINUTES;
            $delay = 0;
        }

        $rawConfig = isset($raw['config']) && is_array($raw['config']) ? $raw['config'] : array();
        $config = array();
        foreach ($types[$type]['fields'] as $key) {
            $value = isset($rawConfig[$key]) && is_scalar($rawConfig[$key]) ? trim((string)$rawConfig[$key]) : '';
            $config[$key] = mb_substr($value, 0, $key === 'body' || $key === 'message' || $key === 'description' ? 20000 : 1000);
        }

        $users = $this->workflowModel->getTenantUsers($this->getTenantId());
        $checkUser = function ($value, $label, $required) use ($users, &$errors) {
            if ($value === '') {
                if ($required) {
                    $errors[] = $label . ' is required';
                }
                return;
            }
            if ($value === '{{assigned_to}}') {
                return;
            }
            if (!ctype_digit($value) || !isset($users[(int)$value])) {
                $errors[] = $label . ' must be an active user of your account';
            }
        };
        $hasRecord = $entityType !== '';

        switch ($type) {
            case 'send_email':
                if ($config['to'] === '') {
                    $errors[] = 'recipient is required';
                } elseif (strpos($config['to'], '{{') === false && !filter_var($config['to'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'recipient must be an email address or a placeholder such as {{email}}';
                }
                if ($config['subject'] === '') {
                    $errors[] = 'subject is required';
                }
                break;
            case 'create_task':
                if ($config['title'] === '') {
                    $errors[] = 'title is required';
                }
                $checkUser($config['assigned_to'], 'assignee', false);
                if ($config['due_in'] !== '' && !preg_match('/^\+?\s*\d{1,4}\s*(minute|min|hour|day|week|month)s?$/i', $config['due_in']) && !ctype_digit($config['due_in'])) {
                    $errors[] = 'due in must look like "+3 days", "2 hours" or "1 week"';
                }
                if (!in_array($config['priority'], array('low', 'medium', 'high', 'urgent'), true)) {
                    $config['priority'] = 'medium';
                }
                break;
            case 'update_field':
                $fields = WorkflowEngine::getUpdatableFields();
                if (!$hasRecord || !isset($fields[$entityType])) {
                    $errors[] = 'this trigger has no record to update';
                } elseif (!isset($fields[$entityType][$config['field']])) {
                    $errors[] = 'choose a ' . $entityType . ' field to update';
                } else {
                    $def = $fields[$entityType][$config['field']];
                    if ($def['type'] === 'enum' && strpos($config['value'], '{{') === false && !in_array($config['value'], $def['values'], true)) {
                        $errors[] = 'value must be one of: ' . implode(', ', $def['values']);
                    }
                }
                break;
            case 'assign_to_user':
                if (!in_array($entityType, array('lead', 'deal', 'task', 'property'), true)) {
                    $errors[] = 'records of this trigger cannot be assigned';
                }
                $checkUser($config['user_id'], 'user', true);
                break;
            case 'add_tag':
                if ($entityType !== 'lead') {
                    $errors[] = 'tags can only be added to leads (use a lead trigger)';
                }
                if ($config['tag'] === '') {
                    $errors[] = 'tag is required';
                } elseif (mb_strlen($config['tag']) > 50) {
                    $errors[] = 'tag must be 50 characters or fewer';
                }
                break;
            case 'send_webhook':
                if ($config['event'] === '') {
                    $config['event'] = 'workflow.triggered';
                }
                if (!preg_match('/^[a-z_]+\.[a-z_]+$/', $config['event'])) {
                    $errors[] = 'invalid event name';
                }
                break;
            case 'send_notification':
                $checkUser($config['user_id'], 'recipient', true);
                if ($config['title'] === '') {
                    $errors[] = 'title is required';
                }
                if ($config['link'] !== '' && strpos($config['link'], '{{') === false && !preg_match('#^/?[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-\.]+)*(\?[A-Za-z0-9_\-=&%\.]*)?$#', $config['link'])) {
                    $errors[] = 'link must be an app path such as leads/view/5';
                }
                break;
            case 'create_deal':
                if ($config['title'] === '') {
                    $errors[] = 'title is required';
                }
                if ($config['deal_value'] !== '' && strpos($config['deal_value'], '{{') === false && !is_numeric($config['deal_value'])) {
                    $errors[] = 'deal value must be a number';
                }
                if (!in_array($config['stage'], array('lead', 'qualified', 'viewing', 'offer', 'negotiation', 'contract'), true)) {
                    $config['stage'] = 'lead';
                }
                $checkUser($config['assigned_to'], 'assignee', false);
                break;
        }

        return array('action_type' => $type, 'action_config' => $config, 'delay_minutes' => (int)$delay);
    }

    /**
     * Action label
     */
    private function actionLabel($type) {
        $types = $this->getActionTypes();
        return isset($types[$type]) ? $types[$type]['name'] : $type;
    }

    /**
     * Trigger types (keys = workflows.trigger_type enum)
     * @return array
     */
    private function getTriggerTypes() {
        return array(
            'lead_created' => 'Lead created',
            'lead_updated' => 'Lead updated',
            'lead_status_changed' => 'Lead status changed',
            'client_created' => 'Client created',
            'property_created' => 'Property created',
            'deal_created' => 'Deal created',
            'deal_status_changed' => 'Deal stage changed',
            'deal_won' => 'Deal won',
            'deal_lost' => 'Deal lost',
            'task_created' => 'Task created',
            'task_completed' => 'Task completed',
            'scheduled' => 'Scheduled (time-based)'
        );
    }

    /**
     * Action types offered in the UI with their config fields
     * ('wait' is not offered: use the per-action delay instead)
     * @return array
     */
    private function getActionTypes() {
        return array(
            'send_notification' => array('name' => 'Send in-app notification', 'fields' => array('user_id', 'title', 'message', 'link')),
            'send_email' => array('name' => 'Send email', 'fields' => array('to', 'subject', 'body')),
            'create_task' => array('name' => 'Create task', 'fields' => array('title', 'description', 'assigned_to', 'due_in', 'priority')),
            'update_field' => array('name' => 'Update field', 'fields' => array('field', 'value')),
            'assign_to_user' => array('name' => 'Assign to user', 'fields' => array('user_id')),
            'add_tag' => array('name' => 'Add tag (leads)', 'fields' => array('tag')),
            'create_deal' => array('name' => 'Create deal', 'fields' => array('title', 'deal_value', 'stage', 'assigned_to')),
            'send_webhook' => array('name' => 'Send webhook', 'fields' => array('event'))
        );
    }
}

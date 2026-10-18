<?php
// FILE: /app/controllers/TasksController.php

/**
 * SplashEstate CRM - Tasks Controller
 */

class TasksController extends Controller {

    private $taskModel;
    private $userModel;

    public function __construct() {
        $this->taskModel = $this->model('Task');
        $this->userModel = $this->model('User');
    }

    /**
     * Task list with filters: status (incl. "open"), priority, assigned_to ("me" allowed), overdue, search
     */
    public function index() {
        $this->requirePermission('tasks.view');
        $tenantId = $this->requireTenant();

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = 20;

        $filters = array();
        foreach (array('status', 'priority', 'assigned_to', 'overdue', 'search') as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key]) && trim($_GET[$key]) !== '') {
                $filters[$key] = trim($_GET[$key]);
            }
        }
        if (isset($filters['status']) && $filters['status'] !== 'open' && !in_array($filters['status'], Task::STATUSES, true)) {
            unset($filters['status']);
        }
        if (isset($filters['priority']) && !in_array($filters['priority'], Task::PRIORITIES, true)) {
            unset($filters['priority']);
        }
        if (isset($filters['assigned_to'])) {
            if ($filters['assigned_to'] === 'me') {
                $filters['assigned_to'] = (string)$this->getUserId();
            } elseif (!ctype_digit($filters['assigned_to'])) {
                unset($filters['assigned_to']);
            }
        }
        if (isset($filters['overdue'])) {
            $filters['overdue'] = '1';
        }

        $tasks = $this->taskModel->getTasksWithInfo($tenantId, $page, $perPage, $filters);
        $totalTasks = $this->taskModel->count($tenantId, $filters);

        $this->view('tasks/index', array(
            'title' => 'Tasks',
            'tasks' => $tasks,
            'currentPage' => $page,
            'totalPages' => (int)ceil($totalTasks / $perPage),
            'totalTasks' => $totalTasks,
            'agents' => $this->userModel->getUsersByTenant($tenantId),
            'filters' => $filters,
            'currentUserId' => (int)$this->getUserId()
        ));
    }

    /**
     * Task detail (URL: /tasks/view/{id})
     * @param int $id Task ID
     */
    public function show($id) {
        $this->requirePermission('tasks.view');
        $tenantId = $this->requireTenant();

        $task = $this->taskModel->getTaskWithInfo((int)$id, $tenantId);
        if (!$task) {
            $this->setFlash('error', 'Task not found');
            $this->redirect('tasks/index');
        }

        $this->view('tasks/view', array(
            'title' => 'Task: ' . $task['title'],
            'task' => $task
        ));
    }

    /**
     * Create task (GET form, POST save).
     * GET accepts ?related_to_type=lead&related_to_id=5 to pre-link the task.
     */
    public function create() {
        $this->requirePermission('tasks.create');
        $tenantId = $this->requireTenant();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processCreate($tenantId);
            return;
        }

        $formData = array('priority' => 'medium', 'status' => 'pending', 'assigned_to' => (string)$this->getUserId());
        $type = isset($_GET['related_to_type']) && is_string($_GET['related_to_type']) ? $_GET['related_to_type'] : '';
        $relId = isset($_GET['related_to_id']) ? (int)$_GET['related_to_id'] : 0;
        if ($type !== '' && $relId > 0 && $this->taskModel->findRelated($tenantId, $type, $relId) !== false) {
            $formData['related_to_type'] = $type;
            $formData['related_to_id'] = (string)$relId;
        }

        $this->renderForm('tasks/create', 'Create Task', $tenantId, $formData, array());
    }

    /**
     * Save a new task
     * @param int $tenantId Tenant ID
     */
    private function processCreate($tenantId) {
        $this->requirePostWithCsrf('tasks/create');

        $formData = $this->collectForm();
        $errors = $this->validateForm($formData, $tenantId);

        if (!empty($errors)) {
            $this->renderForm('tasks/create', 'Create Task', $tenantId, $formData, $errors);
            return;
        }

        $data = $this->toDbValues($formData);
        $data['tenant_id'] = $tenantId;
        $data['created_by'] = $this->getUserId();

        $taskId = $this->taskModel->createTask($data);

        if ($taskId) {
            logActivity($this->getUserId(), $tenantId, 'created', 'task', $taskId);
            $task = $this->taskModel->getById($taskId, $tenantId);
            dispatchEvent($tenantId, 'task.created', $task);
            if ($task['status'] === 'completed') {
                dispatchEvent($tenantId, 'task.completed', $task);
            }
            $this->notifyAssignee($tenantId, $task, null);
            $this->setFlash('success', 'Task created successfully');
            $this->redirect('tasks/view/' . $taskId);
        }

        $this->setFlash('error', 'Failed to create task');
        $this->redirect('tasks/create');
    }

    /**
     * Edit task (GET form, POST save)
     * @param int $id Task ID
     */
    public function edit($id) {
        $this->requirePermission('tasks.edit');
        $tenantId = $this->requireTenant();

        $task = $this->taskModel->getById((int)$id, $tenantId);
        if (!$task) {
            $this->setFlash('error', 'Task not found');
            $this->redirect('tasks/index');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processEdit($task, $tenantId);
            return;
        }

        $formData = $task;
        $formData['due_date'] = $task['due_date'] ? date('Y-m-d\TH:i', strtotime($task['due_date'])) : '';
        $this->renderForm('tasks/edit', 'Edit Task', $tenantId, $formData, array(), $task);
    }

    /**
     * Save task changes
     * @param array $task Current task row
     * @param int $tenantId Tenant ID
     */
    private function processEdit($task, $tenantId) {
        $id = (int)$task['id'];
        $this->requirePostWithCsrf('tasks/edit/' . $id);

        $formData = $this->collectForm();
        $errors = $this->validateForm($formData, $tenantId, $task);

        if (!empty($errors)) {
            $this->renderForm('tasks/edit', 'Edit Task', $tenantId, array_merge($task, $formData), $errors, $task);
            return;
        }

        $data = $this->toDbValues($formData);
        if ($data['status'] === 'completed' && $task['status'] !== 'completed') {
            $data['completed_at'] = date('Y-m-d H:i:s');
        } elseif ($data['status'] !== 'completed') {
            $data['completed_at'] = null;
        }

        if ($this->taskModel->update($id, $tenantId, $data)) {
            logActivity($this->getUserId(), $tenantId, 'updated', 'task', $id);
            $updated = $this->taskModel->getById($id, $tenantId);
            $payload = $updated;
            $payload['old_status'] = $task['status'];
            dispatchEvent($tenantId, 'task.updated', $payload);
            if ($updated['status'] === 'completed' && $task['status'] !== 'completed') {
                logActivity($this->getUserId(), $tenantId, 'completed', 'task', $id);
                dispatchEvent($tenantId, 'task.completed', $payload);
            }
            $this->notifyAssignee($tenantId, $updated, $task['assigned_to']);
            $this->setFlash('success', 'Task updated successfully');
            $this->redirect('tasks/view/' . $id);
        }

        $this->setFlash('error', 'Failed to update task');
        $this->redirect('tasks/edit/' . $id);
    }

    /**
     * Mark a task completed (POST + CSRF)
     * @param int $id Task ID
     */
    public function complete($id) {
        $this->requirePermission('tasks.edit');
        $tenantId = $this->requireTenant();
        $returnTo = $this->returnTo('tasks/index');
        $this->requirePostWithCsrf($returnTo);

        $task = $this->taskModel->getById((int)$id, $tenantId);
        if (!$task) {
            if ($this->wantsJson()) {
                $this->jsonResponse(array('success' => false, 'message' => 'Task not found'), 404);
            }
            $this->setFlash('error', 'Task not found');
            $this->redirect('tasks/index');
        }

        if ($task['status'] === 'completed') {
            $message = 'Task was already completed';
            $ok = true;
        } elseif ($this->taskModel->markCompleted((int)$task['id'], $tenantId)) {
            logActivity($this->getUserId(), $tenantId, 'completed', 'task', $task['id']);
            $updated = $this->taskModel->getById((int)$task['id'], $tenantId);
            $updated['old_status'] = $task['status'];
            dispatchEvent($tenantId, 'task.completed', $updated);
            $message = 'Task marked as completed';
            $ok = true;
        } else {
            $message = 'Failed to update task';
            $ok = false;
        }

        if ($this->wantsJson()) {
            $this->jsonResponse(array('success' => $ok, 'message' => $message), $ok ? 200 : 500);
        }
        $this->setFlash($ok ? 'success' : 'error', $message);
        $this->redirect($returnTo);
    }

    /**
     * Delete a task (POST + CSRF)
     * @param int $id Task ID
     */
    public function delete($id) {
        $this->requirePermission('tasks.delete');
        $tenantId = $this->requireTenant();
        $this->requirePostWithCsrf('tasks/index');

        $task = $this->taskModel->getById((int)$id, $tenantId);
        if (!$task) {
            $this->setFlash('error', 'Task not found');
            $this->redirect('tasks/index');
        }

        if ($this->taskModel->delete((int)$task['id'], $tenantId)) {
            logActivity($this->getUserId(), $tenantId, 'deleted', 'task', $task['id']);
            dispatchEvent($tenantId, 'task.deleted', $task);
            $this->setFlash('success', 'Task deleted successfully');
        } else {
            $this->setFlash('error', 'Failed to delete task');
        }

        $this->redirect('tasks/index');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Current tenant ID, or redirect when the user has no tenant (platform admin)
     * @return int
     */
    private function requireTenant() {
        $tenantId = $this->getTenantId();
        if (empty($tenantId)) {
            $this->setFlash('error', 'Tasks belong to an agency account. Log in as an agency user to manage tasks.');
            $this->redirect('dashboard/index');
        }
        return (int)$tenantId;
    }

    /**
     * Safe internal redirect target from POST return_to (e.g. "leads/view/5")
     * @param string $default
     * @return string
     */
    private function returnTo($default) {
        $value = isset($_POST['return_to']) && is_string($_POST['return_to']) ? trim($_POST['return_to']) : '';
        if ($value !== '' && preg_match('#^[a-z]+/[a-z]+(/\d+)?$#i', $value)) {
            return $value;
        }
        return $default;
    }

    /**
     * Render the create/edit form
     */
    private function renderForm($view, $title, $tenantId, $formData, $errors, $task = null) {
        $relatedName = '';
        if (!empty($formData['related_to_type']) && !empty($formData['related_to_id'])) {
            $name = $this->taskModel->findRelated($tenantId, $formData['related_to_type'], (int)$formData['related_to_id']);
            $relatedName = $name === false ? '' : $name;
        }
        $this->view($view, array(
            'title' => $title,
            'task' => $task,
            'agents' => $this->userModel->getUsersByTenant($tenantId, null, true),
            'errors' => $errors,
            'formData' => $formData,
            'relatedName' => $relatedName
        ));
    }

    /**
     * Collect trimmed raw form values
     * @return array
     */
    private function collectForm() {
        $data = array();
        foreach (array('title', 'description', 'assigned_to', 'due_date', 'priority', 'status', 'related_to_type', 'related_to_id') as $field) {
            $data[$field] = isset($_POST[$field]) && is_string($_POST[$field]) ? trim($_POST[$field]) : '';
        }
        if ($data['status'] === '') {
            $data['status'] = 'pending';
        }
        if ($data['priority'] === '') {
            $data['priority'] = 'medium';
        }
        return $data;
    }

    /**
     * Validate task form data
     * @param array $data Form data
     * @param int $tenantId Tenant ID
     * @param array|null $current Current task row (edit)
     * @return array field => message
     */
    private function validateForm($data, $tenantId, $current = null) {
        $errors = validateRequired($data, array('title'));
        if (strlen($data['title']) > 255) {
            $errors['title'] = 'Title must be at most 255 characters';
        }
        if (!in_array($data['priority'], Task::PRIORITIES, true)) {
            $errors['priority'] = 'Invalid priority';
        }
        if (!in_array($data['status'], Task::STATUSES, true)) {
            $errors['status'] = 'Invalid status';
        }
        if ($data['due_date'] !== '' && $this->parseDate($data['due_date']) === null) {
            $errors['due_date'] = 'Invalid due date';
        }
        if ($data['assigned_to'] !== '') {
            $unchanged = $current !== null && (string)$current['assigned_to'] === $data['assigned_to'];
            if (!ctype_digit($data['assigned_to'])
                || (!$unchanged && !$this->userModel->belongsToTenant((int)$data['assigned_to'], $tenantId))) {
                $errors['assigned_to'] = 'Please choose a user from your team';
            }
        }
        if ($data['related_to_type'] !== '' || $data['related_to_id'] !== '') {
            if (!in_array($data['related_to_type'], Task::RELATED_TYPES, true)
                || !ctype_digit($data['related_to_id'])
                || $this->taskModel->findRelated($tenantId, $data['related_to_type'], (int)$data['related_to_id']) === false) {
                $errors['related_to_type'] = 'The linked record was not found';
            }
        }
        return $errors;
    }

    /**
     * Parse an HTML datetime-local / date value into MySQL DATETIME
     * @param string $value
     * @return string|null
     */
    private function parseDate($value) {
        foreach (array('Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d') as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt !== false && $dt->format($format) === $value) {
                if ($format === 'Y-m-d') {
                    $dt->setTime(17, 0, 0);
                }
                return $dt->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    /**
     * Convert validated form data to DB values
     * @param array $data
     * @return array
     */
    private function toDbValues($data) {
        return array(
            'title' => $data['title'],
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'assigned_to' => $data['assigned_to'] !== '' ? (int)$data['assigned_to'] : null,
            'due_date' => $data['due_date'] !== '' ? $this->parseDate($data['due_date']) : null,
            'priority' => $data['priority'],
            'status' => $data['status'],
            'related_to_type' => $data['related_to_type'] !== '' ? $data['related_to_type'] : null,
            'related_to_id' => $data['related_to_id'] !== '' ? (int)$data['related_to_id'] : null
        );
    }

    /**
     * Notify the assignee when a task is assigned to someone other than the current user
     * @param int $tenantId Tenant ID
     * @param array $task Task row after save
     * @param int|null $previousAssignee Assignee before the change
     */
    private function notifyAssignee($tenantId, $task, $previousAssignee) {
        if (empty($task['assigned_to'])
            || (int)$task['assigned_to'] === (int)$this->getUserId()
            || (int)$task['assigned_to'] === (int)$previousAssignee) {
            return;
        }
        $due = $task['due_date'] ? ' (due ' . date('M j, Y g:i A', strtotime($task['due_date'])) . ')' : '';
        createNotification($tenantId, (int)$task['assigned_to'], 'New task assigned to you',
            $task['title'] . $due . ' - assigned by ' . (isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'a colleague'),
            'tasks/view/' . $task['id'], 'task');
    }
}

<?php
// FILE: /app/controllers/CalendarController.php

/**
 * SplashEstate CRM - Calendar Controller
 * Task calendar (FullCalendar) with JSON endpoints:
 *   GET  /calendar/getTasks?start=Y-m-d&end=Y-m-d[&assigned_to=&status=&priority=]
 *   POST /calendar/createTask      {title, due_date, description?, priority?, assigned_to?, related_to_type?, related_to_id?}
 *   POST /calendar/updateTaskDate  {task_id, new_date}
 * Writes require the CSRF token (X-CSRF-Token header, added by the layout's fetch wrapper).
 */

class CalendarController extends Controller {

    private static $statuses = array('pending', 'in_progress', 'completed', 'cancelled');
    private static $priorities = array('low', 'medium', 'high', 'urgent');
    private static $relatedTables = array('lead' => 'leads', 'client' => 'clients', 'property' => 'properties', 'deal' => 'deals');

    /** @var PDO */
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Calendar page
     */
    public function index() {
        $this->requirePermission('tasks.view');

        $this->view('calendar/index', array(
            'title' => 'Calendar',
            'users' => $this->tenantUsers((int)$this->getTenantId()),
            'canCreate' => can('tasks.create'),
            'canEdit' => can('tasks.edit')
        ));
    }

    /**
     * Tasks in a date range as FullCalendar events (JSON array)
     */
    public function getTasks() {
        $this->requireJsonPermission('tasks.view');
        $tenantId = (int)$this->getTenantId();

        $start = $this->parseDateTime(isset($_GET['start']) ? $_GET['start'] : null);
        $end = $this->parseDateTime(isset($_GET['end']) ? $_GET['end'] : null);
        if ($start === null) {
            $start = date('Y-m-01 00:00:00', strtotime('-1 month'));
        }
        if ($end === null || $end <= $start) {
            $end = date('Y-m-d H:i:s', strtotime($start . ' +62 days'));
        }
        // Never return more than ~1 year at a time
        $maxEnd = date('Y-m-d H:i:s', strtotime($start . ' +400 days'));
        if ($end > $maxEnd) {
            $end = $maxEnd;
        }

        $sql = "SELECT t.id, t.title, t.description, t.due_date, t.priority, t.status, t.assigned_to,
                       t.related_to_type, t.related_to_id,
                       CONCAT(u.first_name, ' ', u.last_name) AS assigned_to_name
                FROM tasks t
                LEFT JOIN users u ON u.id = t.assigned_to AND u.tenant_id = t.tenant_id
                WHERE t.tenant_id = :tenant_id AND t.due_date >= :start_date AND t.due_date < :end_date";
        $params = array(':tenant_id' => $tenantId, ':start_date' => $start, ':end_date' => $end);

        if (isset($_GET['assigned_to']) && is_string($_GET['assigned_to']) && ctype_digit($_GET['assigned_to'])) {
            $sql .= " AND t.assigned_to = :assigned_to";
            $params[':assigned_to'] = (int)$_GET['assigned_to'];
        }
        if (isset($_GET['status']) && in_array($_GET['status'], self::$statuses, true)) {
            $sql .= " AND t.status = :status";
            $params[':status'] = $_GET['status'];
        }
        if (isset($_GET['priority']) && in_array($_GET['priority'], self::$priorities, true)) {
            $sql .= " AND t.priority = :priority";
            $params[':priority'] = $_GET['priority'];
        }
        $sql .= " ORDER BY t.due_date ASC LIMIT 2000";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $editable = can('tasks.edit');
        $events = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
            $color = $this->taskColor($task['priority'], $task['status']);
            $events[] = array(
                'id' => (string)$task['id'],
                'title' => $task['title'],
                'start' => date('Y-m-d\TH:i:s', strtotime($task['due_date'])),
                'allDay' => false,
                'editable' => $editable,
                'backgroundColor' => $color,
                'borderColor' => $color,
                'classNames' => $task['status'] === 'completed' || $task['status'] === 'cancelled' ? array('task-done') : array(),
                'extendedProps' => array(
                    'description' => $task['description'],
                    'status' => $task['status'],
                    'priority' => $task['priority'],
                    'assigned_to' => $task['assigned_to'] !== null ? (int)$task['assigned_to'] : null,
                    'assigned_to_name' => $task['assigned_to_name'],
                    'related_to_type' => $task['related_to_type'],
                    'related_to_id' => $task['related_to_id'] !== null ? (int)$task['related_to_id'] : null
                )
            );
        }

        $this->jsonResponse($events);
    }

    /**
     * Reschedule a task (drag and drop)
     */
    public function updateTaskDate() {
        $this->requireJsonWrite('tasks.edit');
        $tenantId = (int)$this->getTenantId();
        $input = $this->input();

        $taskId = isset($input['task_id']) && is_scalar($input['task_id']) ? (int)$input['task_id'] : 0;
        $newDate = $this->parseDateTime(isset($input['new_date']) ? $input['new_date'] : null);
        if ($taskId <= 0 || $newDate === null) {
            $this->fail('A valid task and date are required', 422);
        }

        $task = $this->loadTask($taskId, $tenantId);
        if (!$task) {
            $this->fail('Task not found', 404);
        }

        $stmt = $this->db->prepare("UPDATE tasks SET due_date = :due_date WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->bindValue(':due_date', $newDate);
        $stmt->bindValue(':id', $taskId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->execute();

        logActivity($this->getUserId(), $tenantId, 'task_rescheduled', 'task', $taskId);
        $fresh = $this->loadTask($taskId, $tenantId);
        if ($fresh) {
            $fresh['old_due_date'] = $task['due_date'];
            dispatchEvent($tenantId, 'task.updated', $fresh);
        }

        $this->jsonResponse(array(
            'success' => true,
            'due_date' => $newDate,
            'message' => 'Task rescheduled'
        ));
    }

    /**
     * Create a task from the calendar
     */
    public function createTask() {
        $this->requireJsonWrite('tasks.create');
        $tenantId = (int)$this->getTenantId();
        $userId = (int)$this->getUserId();
        $input = $this->input();

        $title = isset($input['title']) && is_string($input['title']) ? trim($input['title']) : '';
        $description = isset($input['description']) && is_string($input['description']) ? trim($input['description']) : '';
        $dueDate = $this->parseDateTime(isset($input['due_date']) ? $input['due_date'] : null);
        $priority = isset($input['priority']) && is_string($input['priority']) ? $input['priority'] : 'medium';

        $errors = array();
        if ($title === '') {
            $errors['title'] = 'Title is required';
        } elseif (strlen($title) > 255) {
            $errors['title'] = 'Title must be 255 characters or fewer';
        }
        if ($dueDate === null) {
            $errors['due_date'] = 'A valid due date is required';
        }
        if (!in_array($priority, self::$priorities, true)) {
            $errors['priority'] = 'Invalid priority';
        }

        $assignedTo = $userId;
        if (isset($input['assigned_to']) && $input['assigned_to'] !== '' && $input['assigned_to'] !== null) {
            $assignedTo = is_scalar($input['assigned_to']) ? (int)$input['assigned_to'] : 0;
            if (!$this->isTenantUser($assignedTo, $tenantId)) {
                $errors['assigned_to'] = 'Assignee must be an active user in your organization';
            }
        }

        $relatedType = null;
        $relatedId = null;
        if (!empty($input['related_to_type'])) {
            $relatedType = is_string($input['related_to_type']) ? $input['related_to_type'] : '';
            $relatedId = isset($input['related_to_id']) && is_scalar($input['related_to_id']) ? (int)$input['related_to_id'] : 0;
            if (!isset(self::$relatedTables[$relatedType]) || !$this->recordExists(self::$relatedTables[$relatedType], $relatedId, $tenantId)) {
                $errors['related_to_id'] = 'Related record not found';
            }
        }

        if (!empty($errors)) {
            $this->jsonResponse(array('success' => false, 'error' => reset($errors), 'errors' => $errors), 422);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO tasks (tenant_id, assigned_to, created_by, related_to_type, related_to_id, title, description, due_date, priority, status)
             VALUES (:tenant_id, :assigned_to, :created_by, :related_to_type, :related_to_id, :title, :description, :due_date, :priority, 'pending')"
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':assigned_to', $assignedTo, PDO::PARAM_INT);
        $stmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':related_to_type', $relatedType, $relatedType === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':related_to_id', $relatedId, $relatedId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':description', $description === '' ? null : $description, $description === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':due_date', $dueDate);
        $stmt->bindValue(':priority', $priority);
        $stmt->execute();
        $taskId = (int)$this->db->lastInsertId();

        $task = $this->loadTask($taskId, $tenantId);
        logActivity($userId, $tenantId, 'created', 'task', $taskId);
        if ($task) {
            dispatchEvent($tenantId, 'task.created', $task);
        }
        if ($assignedTo !== $userId) {
            createNotification($tenantId, $assignedTo, 'New task assigned: ' . $title,
                'Due ' . date('M j, Y g:i A', strtotime($dueDate)), 'tasks/view/' . $taskId, 'task');
        }

        $this->jsonResponse(array(
            'success' => true,
            'task_id' => $taskId,
            'task' => $task,
            'message' => 'Task created'
        ), 201);
    }

    // ------------------------------------------------------------------

    /**
     * Login + permission with JSON errors
     * @param string $permission
     */
    private function requireJsonPermission($permission) {
        if (!$this->isLoggedIn()) {
            $this->fail('Authentication required', 401);
        }
        if (!$this->getTenantId()) {
            $this->fail('The calendar is only available inside a tenant account', 403);
        }
        if (!can($permission)) {
            $this->fail('Permission denied', 403);
        }
    }

    /**
     * POST + CSRF + permission with JSON errors
     * @param string $permission
     */
    private function requireJsonWrite($permission) {
        $this->requireJsonPermission($permission);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->fail('Method not allowed', 405);
        }
        $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if ($token === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (!$this->verifyCsrfToken($token)) {
            $this->fail('Invalid security token. Please reload the page.', 419);
        }
    }

    /**
     * Parse "Y-m-d", "Y-m-d H:i[:s]", "Y-m-dTH:i[:s]" (optionally with a
     * timezone suffix, which is ignored: times are stored as local wall time).
     * @param mixed $value
     * @return string|null "Y-m-d H:i:s"
     */
    private function parseDateTime($value) {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value, $m)) {
            return null;
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 1970 || (int)$m[1] > 2100) {
            return null;
        }
        $hour = isset($m[4]) ? (int)$m[4] : 0;
        $minute = isset($m[5]) ? (int)$m[5] : 0;
        $second = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $hour, $minute, $second);
    }

    /**
     * @return array|false
     */
    private function loadTask($id, $tenantId) {
        $stmt = $this->db->prepare("SELECT * FROM tasks WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Active users of the tenant (for filters / assignment)
     * @return array
     */
    private function tenantUsers($tenantId) {
        $stmt = $this->db->prepare(
            "SELECT id, first_name, last_name FROM users
             WHERE tenant_id = :tenant_id AND status = 'active' ORDER BY first_name, last_name"
        );
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return bool
     */
    private function isTenantUser($userId, $tenantId) {
        if ($userId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE id = :id AND tenant_id = :tenant_id AND status = 'active'");
        $stmt->bindValue(':id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param string $table Whitelisted table name
     * @return bool
     */
    private function recordExists($table, $id, $tenantId) {
        if ($id <= 0 || !in_array($table, self::$relatedTables, true)) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT 1 FROM `{$table}` WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    /**
     * JSON body or form fields
     * @return array
     */
    private function input() {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode(file_get_contents('php://input'), true);
            return is_array($decoded) ? $decoded : array();
        }
        return $_POST;
    }

    /**
     * Event colour from priority/status
     * @return string
     */
    private function taskColor($priority, $status) {
        if ($status === 'completed') {
            return '#27ae60';
        }
        if ($status === 'cancelled') {
            return '#95a5a6';
        }
        switch ($priority) {
            case 'urgent':
                return '#e74c3c';
            case 'high':
                return '#f39c12';
            case 'low':
                return '#7f8c8d';
            case 'medium':
            default:
                return '#3498db';
        }
    }

    /**
     * @param string $message
     * @param int $code
     */
    private function fail($message, $code) {
        $this->jsonResponse(array('success' => false, 'error' => $message, 'message' => $message), $code);
    }
}

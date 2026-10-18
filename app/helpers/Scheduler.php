<?php
// FILE: /app/helpers/Scheduler.php

/**
 * SplashEstate CRM - Scheduler
 * Background jobs run by cron.php (once a minute). Each job is isolated: a
 * failure is logged and reported, and the remaining jobs still run. A MySQL
 * named lock prevents overlapping runs.
 */

class Scheduler {

    /** Name of the MySQL advisory lock */
    const LOCK_NAME = 'splashestate_crm_scheduler';

    /** Max task reminders created per run */
    const REMINDER_CAP = 500;

    /** @var PDO */
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
    }

    /**
     * Job name => description
     * @return array
     */
    public static function jobs() {
        return array(
            'workflow_actions' => 'Run due delayed workflow actions',
            'scheduled_workflows' => 'Run due scheduled workflows',
            'webhook_retries' => 'Retry failed webhook deliveries',
            'task_reminders' => 'Notify assignees of tasks due in the next 24 hours',
            'cleanup' => 'Delete expired password resets and login attempts older than 30 days'
        );
    }

    /**
     * Run jobs
     * @param array $only Job names to run (empty = all)
     * @return array job => ['ok' => bool, 'result' => mixed, 'seconds' => float] | ['locked' => true]
     */
    public function run($only = array()) {
        if (!$this->acquireLock()) {
            return array('locked' => true);
        }

        $results = array();
        try {
            foreach (array_keys(self::jobs()) as $job) {
                if (!empty($only) && !in_array($job, $only, true)) {
                    continue;
                }
                $start = microtime(true);
                try {
                    $method = 'job' . str_replace(' ', '', ucwords(str_replace('_', ' ', $job)));
                    $results[$job] = array('ok' => true, 'result' => $this->$method());
                } catch (Throwable $e) {
                    error_log('Scheduler job ' . $job . ' failed: ' . $e->getMessage());
                    $results[$job] = array('ok' => false, 'result' => $e->getMessage());
                }
                $results[$job]['seconds'] = round(microtime(true) - $start, 3);
            }
        } finally {
            $this->releaseLock();
        }
        return $results;
    }

    /**
     * Delayed workflow actions
     * @return array
     */
    private function jobWorkflowActions() {
        require_once APP_PATH . '/helpers/WorkflowEngine.php';
        $engine = new WorkflowEngine();
        return $engine->runDueActions();
    }

    /**
     * Scheduled workflows
     * @return array
     */
    private function jobScheduledWorkflows() {
        require_once APP_PATH . '/helpers/WorkflowEngine.php';
        $engine = new WorkflowEngine();
        return $engine->runScheduledWorkflows();
    }

    /**
     * Failed webhook deliveries
     * @return array
     */
    private function jobWebhookRetries() {
        require_once APP_PATH . '/helpers/WebhookService.php';
        $service = new WebhookService();
        return $service->retryFailed();
    }

    /**
     * One in-app reminder per task due within 24 hours (open, assigned to an
     * active user of the same tenant). Idempotent: skipped when a
     * 'task_reminder' notification for that task and user already exists.
     * @return array
     */
    private function jobTaskReminders() {
        $stmt = $this->conn->prepare(
            "SELECT t.id, t.tenant_id, t.assigned_to, t.title, t.due_date, t.priority
             FROM tasks t
             INNER JOIN users u ON u.id = t.assigned_to AND u.tenant_id = t.tenant_id AND u.status = 'active'
             WHERE t.status IN ('pending', 'in_progress')
               AND t.due_date IS NOT NULL
               AND t.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
               AND NOT EXISTS (
                   SELECT 1 FROM notifications n
                   WHERE n.user_id = t.assigned_to AND n.tenant_id = t.tenant_id
                     AND n.type = 'task_reminder' AND n.link = CONCAT('tasks/view/', t.id)
               )
             ORDER BY t.due_date ASC
             LIMIT " . (int)self::REMINDER_CAP
        );
        $stmt->execute();
        $tasks = $stmt->fetchAll();

        $sent = 0;
        foreach ($tasks as $task) {
            $due = date('M j, Y g:i A', strtotime($task['due_date']));
            $id = createNotification(
                (int)$task['tenant_id'],
                (int)$task['assigned_to'],
                'Task due soon: ' . $task['title'],
                'Due ' . $due . ' (' . $task['priority'] . ' priority).',
                'tasks/view/' . (int)$task['id'],
                'task_reminder'
            );
            if ($id) {
                $sent++;
            }
        }
        return array('due_soon' => count($tasks), 'reminders' => $sent);
    }

    /**
     * Housekeeping
     * @return array
     */
    private function jobCleanup() {
        $resets = $this->conn->exec(
            "DELETE FROM password_resets
             WHERE expires_at < NOW() OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 DAY))"
        );
        $attempts = $this->conn->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        return array('password_resets' => (int)$resets, 'login_attempts' => (int)$attempts);
    }

    /**
     * @return bool
     */
    private function acquireLock() {
        $stmt = $this->conn->prepare("SELECT GET_LOCK(:name, 0)");
        $stmt->bindValue(':name', self::LOCK_NAME);
        $stmt->execute();
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseLock() {
        try {
            $stmt = $this->conn->prepare("SELECT RELEASE_LOCK(:name)");
            $stmt->bindValue(':name', self::LOCK_NAME);
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('Scheduler lock release failed: ' . $e->getMessage());
        }
    }
}

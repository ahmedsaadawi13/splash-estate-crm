<?php
// FILE: /app/models/WebhookLog.php

/**
 * SplashEstate CRM - Webhook Log Model
 * One row per delivery attempt. webhook_logs has no tenant_id column, so every
 * tenant-facing query joins webhooks and filters on webhooks.tenant_id.
 *
 * Retry model: a failed attempt N is retried by a NEW row with attempt N+1;
 * the original row is then marked 'retrying' (= superseded). The final failed
 * attempt (attempt >= max_retries) stays 'failed'.
 */

class WebhookLog extends Model {

    protected $table = 'webhook_logs';

    /** Seconds to wait before retry N (attempt 1 -> 60s, 2 -> 240s, 3 -> 960s, ...) */
    const RETRY_BASE_SECONDS = 60;

    private static $statuses = array('pending', 'success', 'failed', 'retrying');

    /**
     * Create webhook log entry
     * @param array $data Log data
     * @return int|false Log ID
     */
    public function create($data) {
        $allowed = array('webhook_id', 'event_type', 'payload', 'response_code', 'response_body', 'attempt', 'status', 'error_message', 'delivered_at');
        $row = array_intersect_key($data, array_flip($allowed));
        $row += array('attempt' => 1, 'status' => 'pending');
        return parent::create($row);
    }

    /**
     * Get logs for a webhook (caller must have verified the webhook's tenant)
     * @param int $webhookId Webhook ID
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array Logs
     */
    public function getByWebhook($webhookId, $limit = 50, $offset = 0) {
        $sql = "SELECT * FROM {$this->table}
                WHERE webhook_id = :webhook_id
                ORDER BY id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':webhook_id', (int)$webhookId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, (int)$offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Count logs of one webhook
     * @param int $webhookId Webhook ID
     * @return int
     */
    public function countByWebhook($webhookId) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} WHERE webhook_id = :webhook_id");
        $stmt->bindValue(':webhook_id', (int)$webhookId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get logs for a tenant
     * @param int $tenantId Tenant ID
     * @param int $limit Limit
     * @param int $offset Offset
     * @param array $filters Optional 'status', 'webhook_id', 'event_type'
     * @return array Logs with webhook info
     */
    public function getByTenant($tenantId, $limit = 50, $offset = 0, $filters = array()) {
        list($where, $params) = $this->tenantWhere($tenantId, $filters);

        $sql = "SELECT wl.*, w.name AS webhook_name, w.url AS webhook_url
                FROM {$this->table} wl
                INNER JOIN webhooks w ON wl.webhook_id = w.id
                WHERE {$where}
                ORDER BY wl.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, (int)$offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Count logs for a tenant
     * @param int $tenantId Tenant ID
     * @param array $filters See getByTenant()
     * @return int
     */
    public function countByTenant($tenantId, $filters = array()) {
        list($where, $params) = $this->tenantWhere($tenantId, $filters);
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} wl INNER JOIN webhooks w ON wl.webhook_id = w.id WHERE {$where}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Build the tenant-scoped WHERE clause for log listings
     */
    private function tenantWhere($tenantId, $filters) {
        $where = 'w.tenant_id = :tenant_id';
        $params = array(':tenant_id' => (int)$tenantId);

        if (!empty($filters['status']) && in_array($filters['status'], self::$statuses, true)) {
            $where .= ' AND wl.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['webhook_id'])) {
            $where .= ' AND wl.webhook_id = :webhook_id';
            $params[':webhook_id'] = (int)$filters['webhook_id'];
        }
        if (!empty($filters['event_type'])) {
            $where .= ' AND wl.event_type = :event_type';
            $params[':event_type'] = (string)$filters['event_type'];
        }

        return array($where, $params);
    }

    /**
     * Failed deliveries that are due for another attempt.
     * Backoff: attempt N is retried RETRY_BASE_SECONDS * 4^(N-1) seconds after it was made.
     * @param int $limit Max rows
     * @return array Failed logs joined with their webhook configuration
     */
    public function getFailedForRetry($limit = 100) {
        $sql = "SELECT wl.*, w.tenant_id, w.url, w.secret, w.retry_enabled, w.max_retries
                FROM {$this->table} wl
                INNER JOIN webhooks w ON wl.webhook_id = w.id
                WHERE wl.status = 'failed'
                AND w.status = 'active'
                AND w.retry_enabled = 1
                AND wl.attempt < w.max_retries
                AND wl.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND wl.created_at <= DATE_SUB(NOW(), INTERVAL (" . (int)self::RETRY_BASE_SECONDS . " * POW(4, wl.attempt - 1)) SECOND)
                ORDER BY wl.id ASC
                LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Atomically claim a failed row for retry (so overlapping cron runs
     * never retry the same delivery twice)
     * @param int $id Log ID
     * @return bool True if this process claimed it
     */
    public function claimForRetry($id) {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET status = 'retrying' WHERE id = :id AND status = 'failed'");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() === 1;
    }

    /**
     * Count logs by status
     * @param int $webhookId Webhook ID
     * @return array Status counts
     */
    public function countByStatus($webhookId) {
        $sql = "SELECT status, COUNT(*) AS count
                FROM {$this->table}
                WHERE webhook_id = :webhook_id
                GROUP BY status";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':webhook_id', (int)$webhookId, PDO::PARAM_INT);
        $stmt->execute();

        $counts = array_fill_keys(self::$statuses, 0);
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * Delete old logs
     * @param int $days Days to keep
     * @return int Deleted count
     */
    public function deleteOldLogs($days = 30) {
        $sql = "DELETE FROM {$this->table}
                WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':days', max(1, (int)$days), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}

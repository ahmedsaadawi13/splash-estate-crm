<?php
// FILE: /app/models/Webhook.php

/**
 * SplashEstate CRM - Webhook Model
 * Manages outgoing webhook endpoint configurations (tenant scoped).
 *
 * The `events` column stores a comma-separated list of event names
 * (e.g. "lead.created,deal.won") or "*" for every event.
 */

class Webhook extends Model {

    protected $table = 'webhooks';

    /** Columns that may be written through create()/update() */
    private static $writable = array('tenant_id', 'name', 'url', 'secret', 'events', 'status', 'retry_enabled', 'max_retries', 'created_by');

    /**
     * Get every webhook of a tenant with its creator and delivery statistics
     * @param int $tenantId Tenant ID
     * @return array Webhooks
     */
    public function getAllWithStats($tenantId) {
        $sql = "SELECT w.*, u.first_name, u.last_name,
                       COUNT(wl.id) AS total_deliveries,
                       COALESCE(SUM(wl.status = 'success'), 0) AS successful,
                       COALESCE(SUM(wl.status = 'failed'), 0) AS failed,
                       MAX(wl.created_at) AS last_attempt
                FROM {$this->table} w
                LEFT JOIN users u ON w.created_by = u.id
                LEFT JOIN webhook_logs wl ON wl.webhook_id = w.id
                WHERE w.tenant_id = :tenant_id
                GROUP BY w.id
                ORDER BY w.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get active webhooks of a tenant subscribed to an event (exact name or "*")
     * @param int $tenantId Tenant ID
     * @param string $eventType Event type
     * @return array Active webhooks
     */
    public function getActiveForEvent($tenantId, $eventType) {
        // FIND_IN_SET matches whole list items only, so "lead.created" never
        // matches "lead.created_x"; the PHP filter below also tolerates spaces.
        $sql = "SELECT * FROM {$this->table}
                WHERE tenant_id = :tenant_id
                AND status = 'active'
                AND (FIND_IN_SET(:event_type, REPLACE(events, ' ', '')) > 0
                     OR FIND_IN_SET('*', REPLACE(events, ' ', '')) > 0)
                ORDER BY id ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':event_type', (string)$eventType);
        $stmt->execute();

        $filtered = array();
        foreach ($stmt->fetchAll() as $webhook) {
            $events = self::parseEvents($webhook['events']);
            if (in_array($eventType, $events, true) || in_array('*', $events, true)) {
                $filtered[] = $webhook;
            }
        }

        return $filtered;
    }

    /**
     * Create webhook
     * @param array $data Webhook data
     * @return int|false Webhook ID
     */
    public function create($data) {
        $row = array_intersect_key($data, array_flip(self::$writable));
        $row += array(
            'status' => 'active',
            'retry_enabled' => 1,
            'max_retries' => 3
        );
        return parent::create($row);
    }

    /**
     * Update webhook (tenant scoped)
     * @param int $id Webhook ID
     * @param int $tenantId Tenant ID
     * @param array $data Update data
     * @return bool Success
     */
    public function update($id, $tenantId, $data) {
        $row = array_intersect_key($data, array_flip(self::$writable));
        unset($row['tenant_id'], $row['created_by']);
        return parent::update($id, $tenantId, $row);
    }

    /**
     * Get delivery statistics for one webhook
     * @param int $webhookId Webhook ID
     * @return array Statistics
     */
    public function getStats($webhookId) {
        $sql = "SELECT
                    COUNT(*) AS total_deliveries,
                    COALESCE(SUM(status = 'success'), 0) AS successful,
                    COALESCE(SUM(status = 'failed'), 0) AS failed,
                    MAX(delivered_at) AS last_delivery
                FROM webhook_logs
                WHERE webhook_id = :webhook_id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':webhook_id', (int)$webhookId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Generate a random signing secret (fits the varchar(64) column)
     * @return string Secret
     */
    public function generateSecret() {
        return 'whsec_' . bin2hex(random_bytes(24));
    }

    /**
     * Mask a secret for display ("whsec_1a2b…9z")
     * @param string|null $secret Secret
     * @return string
     */
    public static function maskSecret($secret) {
        $secret = (string)$secret;
        if ($secret === '') {
            return '';
        }
        if (strlen($secret) <= 12) {
            return str_repeat('•', 8);
        }
        return substr($secret, 0, 10) . str_repeat('•', 8) . substr($secret, -4);
    }

    /**
     * Get available event types (same names as dispatchEvent())
     * @return array Event types with descriptions
     */
    public function getAvailableEvents() {
        return array(
            '*' => 'All events',
            'lead.created' => 'Lead created',
            'lead.updated' => 'Lead updated',
            'lead.status_changed' => 'Lead status changed',
            'lead.deleted' => 'Lead deleted',
            'client.created' => 'Client created',
            'client.updated' => 'Client updated',
            'client.deleted' => 'Client deleted',
            'property.created' => 'Property listed',
            'property.updated' => 'Property updated',
            'property.deleted' => 'Property removed',
            'deal.created' => 'Deal created',
            'deal.updated' => 'Deal updated',
            'deal.stage_changed' => 'Deal stage changed',
            'deal.won' => 'Deal won',
            'deal.lost' => 'Deal lost',
            'deal.deleted' => 'Deal deleted',
            'task.created' => 'Task created',
            'task.updated' => 'Task updated',
            'task.completed' => 'Task completed',
            'workflow.triggered' => 'Workflow "Send webhook" action',
            'task.deleted' => 'Task deleted'
        );
    }

    /**
     * Keep only known event names; "*" supersedes everything else
     * @param array $events Submitted event names
     * @return string Comma-separated list ('' when none are valid)
     */
    public function normalizeEvents($events) {
        if (!is_array($events)) {
            return '';
        }
        $available = $this->getAvailableEvents();
        $clean = array();
        foreach ($events as $event) {
            if (is_string($event) && isset($available[$event]) && !in_array($event, $clean, true)) {
                $clean[] = $event;
            }
        }
        if (in_array('*', $clean, true)) {
            return '*';
        }
        return implode(',', $clean);
    }

    /**
     * Split a stored events list into an array
     * @param string $events Comma-separated events
     * @return array
     */
    public static function parseEvents($events) {
        return array_values(array_filter(array_map('trim', explode(',', (string)$events)), 'strlen'));
    }
}

<?php
// FILE: /app/models/Notification.php

/**
 * SplashEstate CRM - Notification Model
 * In-app notifications. Every accessor is scoped to the recipient user and,
 * when given, the tenant. Use createNotification() (helpers/functions.php) to create.
 */

class Notification extends Model {

    protected $table = 'notifications';

    /**
     * Build the user/tenant scope
     * @param int|null $tenantId Tenant ID (null for users without a tenant)
     * @param int $userId User ID
     * @return array [sql, params]
     */
    private function scope($tenantId, $userId) {
        $sql = 'user_id = :user_id';
        $params = array(':user_id' => (int)$userId);
        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params[':tenant_id'] = (int)$tenantId;
        }
        return array($sql, $params);
    }

    /**
     * Run a statement with scope params bound as ints
     */
    private function run($sql, $params, $extra = array()) {
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, (int)$value, PDO::PARAM_INT);
        }
        foreach ($extra as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * Notifications of a user, unread first, newest first
     * @param int|null $tenantId Tenant ID
     * @param int $userId User ID
     * @param int $page Page
     * @param int $perPage Per page
     * @param bool $unreadOnly Only unread
     * @return array
     */
    public function getForUser($tenantId, $userId, $page = 1, $perPage = 20, $unreadOnly = false) {
        list($where, $params) = $this->scope($tenantId, $userId);
        if ($unreadOnly) {
            $where .= ' AND is_read = 0';
        }
        $sql = "SELECT * FROM {$this->table} WHERE {$where}
                ORDER BY is_read ASC, created_at DESC, id DESC
                LIMIT :limit OFFSET :offset";
        return $this->run($sql, $params, array(
            ':limit' => max(1, (int)$perPage),
            ':offset' => (max(1, (int)$page) - 1) * max(1, (int)$perPage)
        ))->fetchAll();
    }

    /**
     * Count a user's notifications
     * @param int|null $tenantId Tenant ID
     * @param int $userId User ID
     * @param bool $unreadOnly Only unread
     * @return int
     */
    public function countForUser($tenantId, $userId, $unreadOnly = false) {
        list($where, $params) = $this->scope($tenantId, $userId);
        if ($unreadOnly) {
            $where .= ' AND is_read = 0';
        }
        return (int)$this->run("SELECT COUNT(*) FROM {$this->table} WHERE {$where}", $params)->fetchColumn();
    }

    /**
     * One notification of the user
     * @return array|false
     */
    public function findForUser($id, $tenantId, $userId) {
        list($where, $params) = $this->scope($tenantId, $userId);
        $params[':id'] = (int)$id;
        return $this->run("SELECT * FROM {$this->table} WHERE id = :id AND {$where} LIMIT 1", $params)->fetch();
    }

    /**
     * Mark one notification read
     * @return bool True when the notification exists for this user
     */
    public function markRead($id, $tenantId, $userId) {
        if (!$this->findForUser($id, $tenantId, $userId)) {
            return false;
        }
        list($where, $params) = $this->scope($tenantId, $userId);
        $params[':id'] = (int)$id;
        $this->run("UPDATE {$this->table} SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = :id AND {$where}", $params);
        return true;
    }

    /**
     * Mark all of a user's notifications read
     * @return int Rows changed
     */
    public function markAllRead($tenantId, $userId) {
        list($where, $params) = $this->scope($tenantId, $userId);
        return $this->run("UPDATE {$this->table} SET is_read = 1, read_at = NOW() WHERE {$where} AND is_read = 0", $params)->rowCount();
    }

    /**
     * Delete one notification of the user
     * @return bool
     */
    public function deleteForUser($id, $tenantId, $userId) {
        list($where, $params) = $this->scope($tenantId, $userId);
        $params[':id'] = (int)$id;
        return $this->run("DELETE FROM {$this->table} WHERE id = :id AND {$where}", $params)->rowCount() > 0;
    }

    /**
     * Delete all read notifications of the user
     * @return int Rows deleted
     */
    public function deleteReadForUser($tenantId, $userId) {
        list($where, $params) = $this->scope($tenantId, $userId);
        return $this->run("DELETE FROM {$this->table} WHERE {$where} AND is_read = 1", $params)->rowCount();
    }
}

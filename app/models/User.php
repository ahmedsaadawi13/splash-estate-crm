<?php
// FILE: /app/models/User.php

/**
 * SplashEstate CRM - User Model
 * Handles user authentication and management
 */

class User extends Model {

    protected $table = 'users';

    /**
     * Find user by email
     * @param string $email User email
     * @return mixed User data or false
     */
    public function findByEmail($email) {
        $sql = "SELECT * FROM {$this->table} WHERE email = :email LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':email', trim((string)$email));
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Verify credentials. Does not check status or record the login;
     * the caller decides (see AuthController::processLogin()).
     * Runs password_verify() even for unknown emails so response timing
     * does not reveal which accounts exist.
     * @param string $email User email
     * @param string $password User password
     * @return array|false User data or false
     */
    public function authenticate($email, $password) {
        $user = $this->findByEmail($email);

        if (!$user) {
            password_verify($password, '$2y$10$bcjkO7ibZwfUHMOlkf5pBeKBuevJ2R6WLSk3YboOqEYXKbuUJ3bq6');
            return false;
        }

        if (!password_verify($password, $user['password'])) {
            return false;
        }

        // Transparently upgrade old hashes
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $this->updatePassword($user['id'], $password);
        }

        return $user;
    }

    /**
     * Create new user
     * @param array $data User data
     * @return int|bool User ID or false
     */
    public function createUser($data) {
        // Hash password
        if (isset($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $sql = "INSERT INTO {$this->table} (tenant_id, email, password, first_name, last_name, phone, role, status, created_at)
                VALUES (:tenant_id, :email, :password, :first_name, :last_name, :phone, :role, :status, NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', $data['tenant_id'], PDO::PARAM_INT);
        $stmt->bindValue(':email', $data['email']);
        $stmt->bindValue(':password', $data['password']);
        $stmt->bindValue(':first_name', $data['first_name']);
        $stmt->bindValue(':last_name', $data['last_name']);
        $stmt->bindValue(':phone', isset($data['phone']) ? $data['phone'] : null);
        $stmt->bindValue(':role', isset($data['role']) ? $data['role'] : 'agent');
        $stmt->bindValue(':status', isset($data['status']) ? $data['status'] : 'active');

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    /**
     * Update last login timestamp
     * @param int $userId User ID
     */
    public function updateLastLogin($userId) {
        $sql = "UPDATE {$this->table} SET last_login = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Update user password
     * @param int $userId User ID
     * @param string $newPassword New password
     * @return bool Success status
     */
    public function updatePassword($userId, $newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $sql = "UPDATE {$this->table} SET password = :password WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':password', $hashedPassword);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Get users by tenant
     * @param int $tenantId Tenant ID
     * @param string $role Filter by role (optional)
     * @return array Users
     */
    public function getUsersByTenant($tenantId, $role = null, $activeOnly = false) {
        $sql = "SELECT id, email, first_name, last_name, phone, role, status, last_login, created_at
                FROM {$this->table}
                WHERE tenant_id = :tenant_id";

        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }

        if ($role !== null) {
            $sql .= " AND role = :role";
        }

        $sql .= " ORDER BY first_name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);

        if ($role !== null) {
            $stmt->bindValue(':role', $role);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Update user profile
     * @param int $userId User ID
     * @param array $data Updated data
     * @return bool Success status
     */
    public function updateProfile($userId, $data) {
        $sql = "UPDATE {$this->table}
                SET first_name = :first_name,
                    last_name = :last_name,
                    phone = :phone,
                    updated_at = NOW()
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':first_name', $data['first_name']);
        $stmt->bindValue(':last_name', $data['last_name']);
        $stmt->bindValue(':phone', isset($data['phone']) ? $data['phone'] : null);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Check if email exists
     * @param string $email Email to check
     * @param int $excludeUserId User ID to exclude from check
     * @return bool
     */
    public function emailExists($email, $excludeUserId = null) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE email = :email";

        if ($excludeUserId !== null) {
            $sql .= " AND id != :exclude_id";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':email', $email);

        if ($excludeUserId !== null) {
            $stmt->bindValue(':exclude_id', $excludeUserId, PDO::PARAM_INT);
        }

        $stmt->execute();
        $result = $stmt->fetch();

        return (int)$result['count'] > 0;
    }

    /**
     * Whether a user is an active member of a tenant (use to validate
     * assigned_to / user ids coming from requests)
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @return bool
     */
    public function belongsToTenant($userId, $tenantId) {
        if (empty($userId) || empty($tenantId)) {
            return false;
        }
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} WHERE id = :id AND tenant_id = :tenant_id AND status = 'active'");
        $stmt->bindValue(':id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------
    // Brute-force protection (login_attempts)
    // ------------------------------------------------------------------

    /**
     * Record a login attempt
     * @param string $email Email that was tried
     * @param string $ip Client IP
     * @param bool $success Whether it succeeded
     */
    public function recordLoginAttempt($email, $ip, $success) {
        $stmt = $this->conn->prepare("INSERT INTO login_attempts (email, ip_address, success, attempted_at) VALUES (:email, :ip, :success, NOW())");
        $stmt->bindValue(':email', substr(strtolower(trim((string)$email)), 0, 255));
        $stmt->bindValue(':ip', substr((string)$ip, 0, 45));
        $stmt->bindValue(':success', $success ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Failed attempts for an email in the last N minutes, ignoring failures
     * that happened before the most recent successful login for that email.
     * @param string $email Email
     * @param int $minutes Window
     * @return int
     */
    public function countRecentFailuresByEmail($email, $minutes) {
        $sql = "SELECT COUNT(*) FROM login_attempts
                WHERE email = :email AND success = 0
                AND attempted_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
                AND attempted_at > COALESCE(
                    (SELECT MAX(s.attempted_at) FROM login_attempts s WHERE s.email = :email2 AND s.success = 1),
                    '1970-01-02')";
        $stmt = $this->conn->prepare($sql);
        $email = strtolower(trim((string)$email));
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':email2', $email);
        $stmt->bindValue(':minutes', (int)$minutes, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Failed attempts from an IP in the last N minutes
     * @param string $ip Client IP
     * @param int $minutes Window
     * @return int
     */
    public function countRecentFailuresByIp($ip, $minutes) {
        $sql = "SELECT COUNT(*) FROM login_attempts
                WHERE ip_address = :ip AND success = 0
                AND attempted_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':ip', (string)$ip);
        $stmt->bindValue(':minutes', (int)$minutes, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete old login attempt rows (housekeeping)
     * @param int $days Keep this many days
     */
    public function purgeLoginAttempts($days = 30) {
        $stmt = $this->conn->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
        $stmt->bindValue(':days', (int)$days, PDO::PARAM_INT);
        $stmt->execute();
    }

    // ------------------------------------------------------------------
    // Password resets (password_resets stores only the SHA-256 of the token)
    // ------------------------------------------------------------------

    /**
     * Store a reset token hash, invalidating the user's previous unused tokens
     * @param int $userId User ID
     * @param string $tokenHash sha256 hex of the token
     * @param int $ttlSeconds Lifetime
     * @return bool
     */
    public function createPasswordReset($userId, $tokenHash, $ttlSeconds = 3600) {
        $this->invalidatePasswordResets($userId);
        $stmt = $this->conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :hash, DATE_ADD(NOW(), INTERVAL :ttl SECOND))");
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':hash', $tokenHash);
        $stmt->bindValue(':ttl', (int)$ttlSeconds, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Number of reset tokens issued to a user in the last N minutes
     * @param int $userId User ID
     * @param int $minutes Window
     * @return int
     */
    public function countRecentPasswordResets($userId, $minutes) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = :user_id AND created_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)");
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':minutes', (int)$minutes, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Find an unused, unexpired reset by token hash (joined with an active user)
     * @param string $tokenHash sha256 hex
     * @return array|false Reset row plus email/first_name
     */
    public function findValidPasswordReset($tokenHash) {
        $sql = "SELECT pr.*, u.email, u.first_name
                FROM password_resets pr
                INNER JOIN users u ON u.id = pr.user_id
                WHERE pr.token_hash = :hash AND pr.used_at IS NULL
                AND pr.expires_at > NOW() AND u.status = 'active'
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':hash', (string)$tokenHash);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Mark one reset as used
     * @param int $resetId Reset row ID
     * @return bool True if this call consumed it (prevents double use)
     */
    public function markPasswordResetUsed($resetId) {
        $stmt = $this->conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL");
        $stmt->bindValue(':id', (int)$resetId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Invalidate every unused reset token of a user
     * @param int $userId User ID
     */
    public function invalidatePasswordResets($userId) {
        $stmt = $this->conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL");
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->execute();
    }
}

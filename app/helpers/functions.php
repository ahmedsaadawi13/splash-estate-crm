<?php
// FILE: /app/helpers/functions.php

/**
 * SplashEstate CRM - Global Helper Functions
 * Common utility functions used throughout the application
 */

/**
 * Generate a random API key
 * @return string API key
 */
function generateApiKey() {
    return bin2hex(random_bytes(32));
}

/**
 * Verify API key
 * @param string $apiKey API key to verify
 * @param int $tenantId Tenant ID (optional)
 * @return array|false Tenant data or false
 */
function verifyApiKey($apiKey, $tenantId = null) {
    $db = new Database();
    $conn = $db->connect();

    $sql = "SELECT t.* FROM tenants t WHERE t.api_key = :api_key AND t.status = 'active'";
    if ($tenantId !== null) {
        $sql .= " AND t.id = :tenant_id";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':api_key', $apiKey);
    if ($tenantId !== null) {
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
    }
    $stmt->execute();

    return $stmt->fetch();
}

/**
 * Check subscription quota
 * @param int $tenantId Tenant ID
 * @param string $resource Resource type (leads, properties, agents)
 * @return bool True if within quota
 */
function checkQuota($tenantId, $resource) {
    $db = new Database();
    $conn = $db->connect();

    // Get active subscription with plan details
    $sql = "SELECT p.max_leads, p.max_properties, p.max_agents
            FROM tenant_subscriptions ts
            JOIN plans p ON ts.plan_id = p.id
            WHERE ts.tenant_id = :tenant_id
            AND ts.status = 'active'
            AND (ts.end_date IS NULL OR ts.end_date > NOW())
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
    $stmt->execute();
    $subscription = $stmt->fetch();

    if (!$subscription) {
        return false; // No active subscription
    }

    // Only known resource tables may be counted (prevents SQL injection via $resource)
    $allowedResources = array('leads', 'properties', 'users');
    if (!in_array($resource, $allowedResources, true)) {
        return false;
    }

    // Get current usage
    $usageSql = "SELECT COUNT(*) as count FROM `{$resource}` WHERE tenant_id = :tenant_id";
    $usageStmt = $conn->prepare($usageSql);
    $usageStmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
    $usageStmt->execute();
    $usage = $usageStmt->fetch();

    $currentCount = (int)$usage['count'];

    // Check against limit
    $limit = 0;
    switch ($resource) {
        case 'leads':
            $limit = (int)$subscription['max_leads'];
            break;
        case 'properties':
            $limit = (int)$subscription['max_properties'];
            break;
        case 'users':
            $limit = (int)$subscription['max_agents'];
            break;
    }

    return ($limit === -1) || ($currentCount < $limit);
}

/**
 * Log activity
 * @param int $userId User ID
 * @param int $tenantId Tenant ID
 * @param string $action Action performed
 * @param string $entity Entity type
 * @param int $entityId Entity ID
 */
function logActivity($userId, $tenantId, $action, $entity, $entityId) {
    $db = new Database();
    $conn = $db->connect();

    $sql = "INSERT INTO activity_logs (user_id, tenant_id, action, entity_type, entity_id, ip_address, user_agent, created_at)
            VALUES (:user_id, :tenant_id, :action, :entity_type, :entity_id, :ip_address, :user_agent, NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
    $stmt->bindValue(':action', $action);
    $stmt->bindValue(':entity_type', $entity);
    $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
    $stmt->bindValue(':ip_address', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);
    $stmt->bindValue(':user_agent', isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null);
    $stmt->execute();
}

/**
 * Upload file
 * @param array $file File from $_FILES
 * @param int $tenantId Tenant ID
 * @return array Result with success status and file path or error
 */
function uploadFile($file, $tenantId) {
    $result = array('success' => false, 'message' => '', 'path' => '');

    // Check if file was uploaded
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $result['message'] = 'No file uploaded';
        return $result;
    }

    // Check file size
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        $result['message'] = 'File size exceeds maximum allowed size';
        return $result;
    }

    // Get file extension
    $fileName = $file['name'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Check file type
    $allowedTypes = explode(',', ALLOWED_FILE_TYPES);
    if (!in_array($fileExt, $allowedTypes)) {
        $result['message'] = 'File type not allowed';
        return $result;
    }

    // Generate unique filename
    $uniqueName = bin2hex(random_bytes(16)) . '.' . $fileExt;

    // Create tenant directory if not exists
    $tenantDir = UPLOAD_PATH . '/' . $tenantId;
    if (!file_exists($tenantDir)) {
        mkdir($tenantDir, 0755, true);
    }

    // Move file
    $destination = $tenantDir . '/' . $uniqueName;
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $result['success'] = true;
        $result['path'] = $tenantId . '/' . $uniqueName;
        $result['message'] = 'File uploaded successfully';
    } else {
        $result['message'] = 'Failed to move uploaded file';
    }

    return $result;
}

/**
 * Delete file
 * @param string $filePath File path relative to upload directory
 * @return bool Success status
 */
function deleteFile($filePath) {
    $fullPath = UPLOAD_PATH . '/' . $filePath;
    if (file_exists($fullPath)) {
        return unlink($fullPath);
    }
    return false;
}

/**
 * Send email (placeholder for future implementation)
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $message Email message
 * @return bool Success status
 */
function sendEmail($to, $subject, $message) {
    try {
        require_once APP_PATH . '/helpers/EmailService.php';
        $emailService = new EmailService();
        return (bool)$emailService->sendEmail($to, $subject, $message);
    } catch (Exception $e) {
        error_log('sendEmail failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Format phone number
 * @param string $phone Phone number
 * @return string Formatted phone number
 */
function formatPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 10) {
        return '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6);
    }
    return $phone;
}

/**
 * Generate random password
 * @param int $length Password length
 * @return string Random password
 */
function generatePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

/**
 * Validate required fields
 * @param array $data Data to validate
 * @param array $required Required fields
 * @return array Validation errors
 */
function validateRequired($data, $required) {
    $errors = array();
    foreach ($required as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    return $errors;
}

/**
 * Get file icon based on extension
 * @param string $extension File extension
 * @return string Icon class
 */
function getFileIcon($extension) {
    $icons = array(
        'pdf' => 'file-pdf',
        'doc' => 'file-word',
        'docx' => 'file-word',
        'xls' => 'file-excel',
        'xlsx' => 'file-excel',
        'jpg' => 'file-image',
        'jpeg' => 'file-image',
        'png' => 'file-image',
        'gif' => 'file-image'
    );

    return isset($icons[$extension]) ? $icons[$extension] : 'file';
}

/**
 * Trigger webhook event
 * @param int $tenantId Tenant ID
 * @param string $eventType Event type (e.g., 'lead.created', 'property.updated')
 * @param array $data Event data
 * @return bool Success
 */
function triggerWebhook($tenantId, $eventType, $data) {
    try {
        require_once APP_PATH . '/helpers/WebhookService.php';
        $webhookService = new WebhookService();
        return $webhookService->trigger($tenantId, $eventType, $data);
    } catch (Throwable $e) {
        error_log('Webhook trigger failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if user has permission
 * @param int $userId User ID
 * @param string $permissionSlug Permission slug (e.g., 'leads.create')
 * @return bool Has permission
 */
function hasPermission($userId, $permissionSlug) {
    static $userPermissions = array();

    // Cache permissions per user (per request)
    if (!isset($userPermissions[$userId])) {
        $userPermissions[$userId] = loadUserPermissions($userId);
    }

    return in_array($permissionSlug, $userPermissions[$userId], true);
}

/**
 * Load permission slugs for a user.
 * Uses the user's RBAC roles (user_roles). Users with no RBAC roles fall back
 * to the system role matching their legacy users.role column
 * (tenant_admin -> "Tenant Admin", agent -> "Agent"), so existing accounts keep working.
 * @param int $userId User ID
 * @return array Permission slugs
 */
function loadUserPermissions($userId) {
    try {
        $db = new Database();
        $conn = $db->connect();

        $stmt = $conn->prepare(
            "SELECT DISTINCT p.slug
             FROM permissions p
             INNER JOIN role_permissions rp ON p.id = rp.permission_id
             INNER JOIN user_roles ur ON rp.role_id = ur.role_id
             WHERE ur.user_id = :user_id"
        );
        $stmt->execute(array(':user_id' => (int)$userId));
        $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($slugs)) {
            return $slugs;
        }

        // Has explicit roles but none grant permissions -> no permissions
        $check = $conn->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = :user_id");
        $check->execute(array(':user_id' => (int)$userId));
        if ((int)$check->fetchColumn() > 0) {
            return array();
        }

        // Legacy fallback: map users.role to a system role
        $stmt = $conn->prepare(
            "SELECT DISTINCT p.slug
             FROM users u
             INNER JOIN roles r ON r.slug = u.role AND r.is_system = 1
             INNER JOIN role_permissions rp ON rp.role_id = r.id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = :user_id"
        );
        $stmt->execute(array(':user_id' => (int)$userId));
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('loadUserPermissions failed: ' . $e->getMessage());
        return array();
    }
}

/**
 * Check if current session user has permission
 * @param string $permissionSlug Permission slug
 * @return bool Has permission
 */
function can($permissionSlug) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    // Platform admins have all permissions
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'platform_admin') {
        return true;
    }

    return hasPermission($_SESSION['user_id'], $permissionSlug);
}

/**
 * Get user's roles
 * @param int $userId User ID
 * @return array Roles
 */
function getUserRoles($userId) {
    static $userRoles = array();

    if (!isset($userRoles[$userId])) {
        require_once APP_PATH . '/models/Role.php';
        $roleModel = new Role();
        $userRoles[$userId] = $roleModel->getUserRoles($userId);
    }

    return $userRoles[$userId];
}

/**
 * Escape a value for HTML output
 * @param mixed $value Value
 * @return string
 */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Build an absolute application URL
 * @param string $path Path such as "leads/view/5"
 * @return string
 */
function url($path = '') {
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Get (creating if needed) the session CSRF token
 * @return string
 */
function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden CSRF input for forms
 * @return string HTML
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Map of application events to workflow trigger types.
 * Event names use dot notation (same names as webhook events).
 * @return array event => workflow trigger_type
 */
function getWorkflowTriggerMap() {
    return array(
        'lead.created' => 'lead_created',
        'lead.updated' => 'lead_updated',
        'lead.status_changed' => 'lead_status_changed',
        'client.created' => 'client_created',
        'property.created' => 'property_created',
        'deal.created' => 'deal_created',
        'deal.stage_changed' => 'deal_status_changed',
        'deal.won' => 'deal_won',
        'deal.lost' => 'deal_lost',
        'task.created' => 'task_created',
        'task.completed' => 'task_completed'
    );
}

/**
 * Dispatch an application event to every subscriber:
 *   1. Lead scoring (recalculates the lead score on lead.* create/update events)
 *   2. Workflow automation (runs active workflows whose trigger matches)
 *   3. Outgoing webhooks (delivers to subscribed endpoints)
 *
 * Failures are logged and never break the request that raised the event.
 * Events raised while another event is being handled (e.g. by a workflow
 * action) are limited in depth to prevent infinite loops.
 *
 * @param int $tenantId Tenant ID
 * @param string $event Event name, e.g. 'lead.created', 'deal.won'
 * @param array $data Entity data (should include 'id')
 * @return void
 */
function dispatchEvent($tenantId, $event, $data) {
    static $depth = 0;

    if ($depth >= 3 || empty($tenantId)) {
        return;
    }
    $depth++;

    try {
        $data = is_array($data) ? $data : array();

        // 1. Lead scoring first so workflows/webhooks see the fresh score
        if (in_array($event, array('lead.created', 'lead.updated', 'lead.status_changed'), true) && !empty($data['id'])) {
            try {
                require_once APP_PATH . '/helpers/LeadScoringEngine.php';
                $scoring = new LeadScoringEngine();
                $scoring->calculateScore((int)$data['id'], $event);
                $score = $scoring->getLeadScore((int)$data['id']);
                if ($score) {
                    $data['score'] = (int)$score['score'];
                    $data['grade'] = $score['grade'];
                }
            } catch (Throwable $e) {
                error_log("Lead scoring failed for {$event}: " . $e->getMessage());
            }
        }

        // 2. Workflow automation
        $triggerMap = getWorkflowTriggerMap();
        if (isset($triggerMap[$event])) {
            try {
                require_once APP_PATH . '/helpers/WorkflowEngine.php';
                $engine = new WorkflowEngine();
                $engine->trigger($tenantId, $triggerMap[$event], $data);
            } catch (Throwable $e) {
                error_log("Workflow trigger failed for {$event}: " . $e->getMessage());
            }
        }

        // 3. Outgoing webhooks
        triggerWebhook($tenantId, $event, $data);
    } catch (Throwable $e) {
        error_log("dispatchEvent failed for {$event}: " . $e->getMessage());
    }

    $depth--;
}

/**
 * Create an in-app notification for a user
 * @param int $tenantId Tenant ID
 * @param int $userId Recipient user ID
 * @param string $title Title
 * @param string $message Message body
 * @param string|null $link Relative app path (e.g. "leads/view/5")
 * @param string $type info, success, warning, task, lead, deal, workflow
 * @return int|false Notification ID
 */
function createNotification($tenantId, $userId, $title, $message = '', $link = null, $type = 'info') {
    try {
        $db = new Database();
        $conn = $db->connect();
        $stmt = $conn->prepare(
            "INSERT INTO notifications (tenant_id, user_id, type, title, message, link)
             VALUES (:tenant_id, :user_id, :type, :title, :message, :link)"
        );
        $stmt->execute(array(
            ':tenant_id' => (int)$tenantId,
            ':user_id' => (int)$userId,
            ':type' => substr((string)$type, 0, 50),
            ':title' => substr((string)$title, 0, 255),
            ':message' => (string)$message,
            ':link' => $link !== null ? substr((string)$link, 0, 512) : null
        ));
        return (int)$conn->lastInsertId();
    } catch (Throwable $e) {
        error_log('createNotification failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Number of unread notifications for the logged-in user (0 on error)
 * @return int
 */
function unreadNotificationCount() {
    static $count = null;
    if ($count !== null) {
        return $count;
    }
    $count = 0;
    if (empty($_SESSION['user_id'])) {
        return $count;
    }
    try {
        $db = new Database();
        $conn = $db->connect();
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0");
        $stmt->execute(array(':user_id' => (int)$_SESSION['user_id']));
        $count = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $count = 0;
    }
    return $count;
}


<?php
// FILE: /app/controllers/UsersController.php

/**
 * SplashEstate CRM - Team (user) management
 * List, invite/create, edit, activate/deactivate users of the tenant and
 * assign RBAC roles. Requires users.manage; role assignment additionally
 * requires roles.manage. Platform admins see and manage every tenant.
 *
 * Anti-escalation rules (non platform admins):
 *  - only users of the same tenant can be managed;
 *  - a user whose permissions exceed yours cannot be edited/deactivated;
 *  - you can only assign roles whose permissions you hold yourself;
 *  - you cannot deactivate yourself, and a tenant always keeps one active Tenant Admin.
 */

class UsersController extends Controller {

    /** Invitation / reset link lifetime in seconds (72h) */
    const INVITE_TTL = 259200;

    private $userModel;
    private $roleModel;

    public function __construct() {
        $this->userModel = $this->model('User');
        $this->roleModel = $this->model('Role');
    }

    /**
     * Team list
     */
    public function index() {
        $this->requirePermission('users.manage');
        $tenantId = $this->scopeTenantId();

        $status = isset($_GET['status']) && in_array($_GET['status'], array('active', 'inactive'), true) ? $_GET['status'] : '';
        $search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';

        $sql = "SELECT u.id, u.tenant_id, u.email, u.first_name, u.last_name, u.phone, u.role, u.status,
                       u.last_login, u.created_at, t.name AS tenant_name
                FROM users u
                LEFT JOIN tenants t ON t.id = u.tenant_id
                WHERE 1 = 1";
        $params = array();
        if ($tenantId !== null) {
            $sql .= " AND u.tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }
        if ($status !== '') {
            $sql .= " AND u.status = :status";
            $params[':status'] = $status;
        }
        if ($search !== '') {
            $sql .= " AND (u.first_name LIKE :q1 OR u.last_name LIKE :q2 OR u.email LIKE :q3)";
            $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY t.name, u.first_name, u.last_name";
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        $this->view('users/index', array(
            'title' => 'Team',
            'users' => $stmt->fetchAll(),
            'rolesByUser' => $this->roleModel->getRolesByUser($tenantId),
            'isPlatform' => $this->isPlatformAdmin(),
            'status' => $status,
            'search' => $search,
            'currentUserId' => (int)$this->getUserId(),
            'quotaOk' => $tenantId === null ? true : checkQuota($tenantId, 'users')
        ));
    }

    /**
     * Invite / create a user (GET form, POST save)
     */
    public function create() {
        $this->requirePermission('users.manage');
        $scope = $this->scopeTenantId();

        $form = array(
            'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '',
            'tenant_id' => $scope !== null ? $scope : '', 'roles' => array(), 'password_mode' => 'reset_link'
        );
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('users/create');
            $form = $this->readForm(true);
            $tenantId = $scope !== null ? $scope : (int)$form['tenant_id'];
            $form['tenant_id'] = $tenantId;

            $errors = $this->validateProfile($form, null);
            if ($scope === null && !$this->tenantExists($tenantId)) {
                $errors['tenant_id'] = 'Choose an organization.';
            }

            $roleIds = array();
            if (empty($errors)) {
                if (can('roles.manage') && !empty($form['roles'])) {
                    $roleIds = $form['roles'];
                    $roleError = $this->checkAssignableRoles($roleIds, $tenantId);
                    if ($roleError !== null) {
                        $errors['roles'] = $roleError;
                    }
                } else {
                    $agent = $this->roleModel->getBySlug(Role::AGENT_SLUG);
                    $roleIds = $agent ? array((int)$agent['id']) : array();
                }
            }

            if (empty($errors) && !checkQuota($tenantId, 'users')) {
                $errors['general'] = 'Your plan\'s user limit has been reached. Upgrade the subscription to add more users.';
            }

            if (empty($errors)) {
                $this->createUser($form, $tenantId, $roleIds);
            }
        }

        $this->renderForm('Invite Team Member', $form, $errors, null);
    }

    /**
     * Edit a user's profile and roles (GET form, POST save)
     * @param int $id User ID
     */
    public function edit($id) {
        $this->requirePermission('users.manage');
        $user = $this->findManageableUser($id);
        $tenantId = (int)$user['tenant_id'];
        $currentRoles = array_map('intval', array_column($this->roleModel->getUserRoles($user['id']), 'id'));

        $displayRoles = $currentRoles;
        if (empty($displayRoles)) {
            // No explicit RBAC roles: permissions come from the system role matching users.role
            $legacy = $this->roleModel->getBySlug($user['role']);
            if ($legacy) {
                $displayRoles = array((int)$legacy['id']);
            }
        }
        $form = array(
            'first_name' => $user['first_name'], 'last_name' => $user['last_name'], 'email' => $user['email'],
            'phone' => (string)$user['phone'], 'tenant_id' => $tenantId, 'roles' => $displayRoles
        );
        $errors = array();
        $canAssign = $this->canAssignRolesTo($user);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('users/edit/' . (int)$user['id']);
            $form = $this->readForm(false);
            $form['tenant_id'] = $tenantId;
            $errors = $this->validateProfile($form, (int)$user['id']);

            $rolesChanged = false;
            if (empty($errors) && $canAssign && isset($_POST['roles_submitted'])) {
                sort($currentRoles);
                $newRoles = $form['roles'];
                sort($newRoles);
                if ($newRoles !== $currentRoles) {
                    $roleError = $this->checkAssignableRoles($newRoles, $tenantId);
                    if ($roleError !== null) {
                        $errors['roles'] = $roleError;
                    } else {
                        $rolesChanged = true;
                    }
                }
            }

            if (empty($errors)) {
                $stmt = $this->db()->prepare(
                    "UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, phone = :phone
                     WHERE id = :id AND tenant_id = :tenant_id"
                );
                $stmt->execute(array(
                    ':first_name' => $form['first_name'],
                    ':last_name' => $form['last_name'],
                    ':email' => $form['email'],
                    ':phone' => $form['phone'] !== '' ? $form['phone'] : null,
                    ':id' => (int)$user['id'],
                    ':tenant_id' => $tenantId
                ));

                $result = true;
                if ($rolesChanged) {
                    $result = $this->roleModel->setUserRoles($user['id'], $form['roles'], $tenantId);
                }
                if ($result === true) {
                    logActivity($this->getUserId(), $tenantId, $rolesChanged ? 'updated_roles' : 'updated', 'user', (int)$user['id']);
                    if ((int)$user['id'] === (int)$this->getUserId()) {
                        $_SESSION['user_name'] = $form['first_name'] . ' ' . $form['last_name'];
                    }
                    $this->setFlash('success', 'User updated.');
                    $this->redirect('users/index');
                }
                $errors['roles'] = $result;
            }
        }

        $this->renderForm('Edit Team Member', $form, $errors, $user);
    }

    /**
     * Activate / deactivate a user (POST)
     * @param int $id User ID
     */
    public function toggleStatus($id) {
        $this->requirePermission('users.manage');
        $this->requirePostWithCsrf('users/index');
        $user = $this->findManageableUser($id);
        $tenantId = (int)$user['tenant_id'];

        if ((int)$user['id'] === (int)$this->getUserId()) {
            $this->setFlash('error', 'You cannot deactivate your own account.');
            $this->redirect('users/index');
        }

        $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
        if ($newStatus === 'inactive' && $this->roleModel->isTenantAdmin($user['id'])
            && $this->roleModel->countActiveTenantAdmins($tenantId, $user['id']) === 0) {
            $this->setFlash('error', 'You cannot deactivate the last active Tenant Admin.');
            $this->redirect('users/index');
        }
        $stmt = $this->db()->prepare("UPDATE users SET status = :status WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->execute(array(':status' => $newStatus, ':id' => (int)$user['id'], ':tenant_id' => $tenantId));
        logActivity($this->getUserId(), $tenantId, $newStatus === 'active' ? 'activated' : 'deactivated', 'user', (int)$user['id']);

        $this->setFlash('success', $user['first_name'] . ' ' . $user['last_name'] . ' is now ' . $newStatus . '.');
        $this->redirect('users/index');
    }

    /**
     * Email the user a new password-setup link (POST)
     * @param int $id User ID
     */
    public function sendReset($id) {
        $this->requirePermission('users.manage');
        $this->requirePostWithCsrf('users/index');
        $user = $this->findManageableUser($id);

        $link = $this->issueResetLink($user['id']);
        $sent = sendEmail(
            $user['email'],
            'Set your SplashEstate CRM password',
            $this->inviteEmailHtml($user['first_name'], null, $link)
        );
        logActivity($this->getUserId(), (int)$user['tenant_id'], 'sent_password_reset', 'user', (int)$user['id']);
        if ($sent) {
            $this->setFlash('success', 'A password setup link was emailed to ' . $user['email'] . '.');
        } else {
            $this->setFlash('warning', 'Email could not be sent. Share this one-time link with the user (valid 72 hours): ' . $link);
        }
        $this->redirect('users/edit/' . (int)$user['id']);
    }

    // ------------------------------------------------------------------

    /**
     * @return PDO
     */
    private function db() {
        return $this->userModel->getConnection();
    }

    /**
     * @return bool
     */
    private function isPlatformAdmin() {
        return $this->getUserRole() === 'platform_admin';
    }

    /**
     * Tenant scope: null (all tenants) for platform admins without a tenant
     * @return int|null
     */
    private function scopeTenantId() {
        $tenantId = $this->getTenantId();
        if (empty($tenantId)) {
            if ($this->isPlatformAdmin()) {
                return null;
            }
            $this->setFlash('error', 'No organization is associated with your account.');
            $this->redirect('dashboard/index');
        }
        return (int)$tenantId;
    }

    /**
     * @param int $tenantId
     * @return bool
     */
    private function tenantExists($tenantId) {
        if ($tenantId <= 0) {
            return false;
        }
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM tenants WHERE id = :id");
        $stmt->execute(array(':id' => (int)$tenantId));
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Load a tenant user the current user may manage, or redirect
     * @param int $id User ID
     * @return array
     */
    private function findManageableUser($id) {
        $scope = $this->scopeTenantId();
        $sql = "SELECT id, tenant_id, email, first_name, last_name, phone, role, status, last_login, created_at
                FROM users WHERE id = :id AND tenant_id IS NOT NULL";
        $params = array(':id' => (int)$id);
        if ($scope !== null) {
            $sql .= " AND tenant_id = :tenant_id";
            $params[':tenant_id'] = $scope;
        }
        $stmt = $this->db()->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        $user = $stmt->fetch();
        if (!$user) {
            $this->setFlash('error', 'User not found.');
            $this->redirect('users/index');
        }
        if (!$this->isPlatformAdmin() && (int)$user['id'] !== (int)$this->getUserId()
            && !$this->isSubset(loadUserPermissions($user['id']), $this->ownPermissions())) {
            $this->setFlash('error', 'You cannot manage a user who has more permissions than you.');
            $this->redirect('users/index');
        }
        return $user;
    }

    /**
     * @return array Current user's permission slugs
     */
    private function ownPermissions() {
        return loadUserPermissions($this->getUserId());
    }

    /**
     * @param array $needles
     * @param array $haystack
     * @return bool
     */
    private function isSubset($needles, $haystack) {
        return count(array_diff($needles, $haystack)) === 0;
    }

    /**
     * Whether the current user may change the target's roles: needs roles.manage
     * and every role the target currently holds must be assignable by the current user.
     * @param array $user Target user
     * @return bool
     */
    private function canAssignRolesTo($user) {
        if (!can('roles.manage')) {
            return false;
        }
        if ($this->isPlatformAdmin()) {
            return true;
        }
        $own = $this->ownPermissions();
        foreach ($this->roleModel->getUserRoles($user['id']) as $role) {
            if (!$this->isSubset($this->roleModel->getPermissionSlugs($role['id']), $own)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate roles for assignment within a tenant
     * @param array $roleIds
     * @param int $tenantId
     * @return string|null Error message or null when OK
     */
    private function checkAssignableRoles($roleIds, $tenantId) {
        if (empty($roleIds)) {
            return 'Select at least one role.';
        }
        $own = $this->isPlatformAdmin() ? null : $this->ownPermissions();
        foreach ($roleIds as $roleId) {
            $role = $this->roleModel->getAccessibleRole($roleId, $tenantId);
            if (!$role) {
                return 'Invalid role selected.';
            }
            if ($own !== null && !$this->isSubset($this->roleModel->getPermissionSlugs($role['id']), $own)) {
                return 'You cannot assign the role "' . $role['name'] . '" because it grants permissions you do not have.';
            }
        }
        return null;
    }

    /**
     * Roles shown in the form for a tenant, flagged with whether the current user may assign them
     * @param int|null $tenantId
     * @return array
     */
    private function roleOptions($tenantId) {
        if (empty($tenantId)) {
            $roles = array_values(array_filter($this->roleModel->getRolesForTenant(null), function ($r) {
                return $r['tenant_id'] === null;
            }));
        } else {
            $roles = $this->roleModel->getRolesForTenant((int)$tenantId);
        }
        $own = $this->isPlatformAdmin() ? null : $this->ownPermissions();
        foreach ($roles as &$role) {
            $role['assignable'] = $own === null || $this->isSubset($this->roleModel->getPermissionSlugs($role['id']), $own);
        }
        unset($role);
        return $roles;
    }

    /**
     * Read the user form (raw trimmed values)
     * @param bool $isCreate
     * @return array
     */
    private function readForm($isCreate) {
        $str = function ($key) {
            return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
        };
        $roles = isset($_POST['roles']) && is_array($_POST['roles']) ? $_POST['roles'] : array();
        $form = array(
            'first_name' => $str('first_name'),
            'last_name' => $str('last_name'),
            'email' => strtolower($str('email')),
            'phone' => $str('phone'),
            'tenant_id' => (int)$str('tenant_id'),
            'roles' => array_values(array_unique(array_filter(array_map('intval', $roles))))
        );
        if ($isCreate) {
            $form['password_mode'] = $str('password_mode') === 'temp_password' ? 'temp_password' : 'reset_link';
        }
        return $form;
    }

    /**
     * @param array $form
     * @param int|null $userId Existing user (for unique email check)
     * @return array field => error
     */
    private function validateProfile($form, $userId) {
        $errors = validateRequired($form, array('first_name', 'last_name', 'email'));
        if (strlen($form['first_name']) > 100) {
            $errors['first_name'] = 'First name is too long.';
        }
        if (strlen($form['last_name']) > 100) {
            $errors['last_name'] = 'Last name is too long.';
        }
        if (strlen($form['phone']) > 50) {
            $errors['phone'] = 'Phone is too long.';
        }
        if (!isset($errors['email'])) {
            if (!$this->validateEmail($form['email']) || strlen($form['email']) > 255) {
                $errors['email'] = 'Enter a valid email address.';
            } elseif ($this->userModel->emailExists($form['email'], $userId)) {
                $errors['email'] = 'This email address is already in use.';
            }
        }
        return $errors;
    }

    /**
     * Create the user, assign roles, send the invitation and redirect
     * @param array $form Validated form
     * @param int $tenantId Tenant
     * @param array $roleIds Validated role IDs
     */
    private function createUser($form, $tenantId, $roleIds) {
        $tempPassword = $form['password_mode'] === 'temp_password' ? generatePassword(12) : null;
        $db = $this->db();

        try {
            $db->beginTransaction();
            $userId = $this->userModel->createUser(array(
                'tenant_id' => $tenantId,
                'email' => $form['email'],
                // Invite-by-link accounts get an unguessable password until they set one
                'password' => $tempPassword !== null ? $tempPassword : bin2hex(random_bytes(32)),
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'phone' => $form['phone'] !== '' ? $form['phone'] : null,
                'role' => 'agent',
                'status' => 'active'
            ));
            if (!$userId) {
                throw new RuntimeException('createUser returned false');
            }
            if (!empty($roleIds)) {
                $result = $this->roleModel->setUserRoles($userId, $roleIds, $tenantId);
                if ($result !== true) {
                    throw new RuntimeException(is_string($result) ? $result : 'Role assignment failed');
                }
            }
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('UsersController::createUser failed: ' . $e->getMessage());
            $this->setFlash('error', 'Could not create the user. Please try again.');
            $this->redirect('users/create');
        }

        logActivity($this->getUserId(), $tenantId, 'created', 'user', (int)$userId);

        $link = $tempPassword === null ? $this->issueResetLink($userId) : null;
        $sent = sendEmail(
            $form['email'],
            'You have been invited to SplashEstate CRM',
            $this->inviteEmailHtml($form['first_name'], $tempPassword, $link)
        );

        $name = $form['first_name'] . ' ' . $form['last_name'];
        if ($tempPassword !== null) {
            // Shown exactly once; never stored in plain text
            $this->setFlash('success', sprintf(
                '%s was added. Temporary password (shown only once): %s%s',
                $name,
                $tempPassword,
                $sent ? ' — it was also emailed to them.' : ' — the invitation email could not be sent, share it securely.'
            ));
        } elseif ($sent) {
            $this->setFlash('success', $name . ' was added and an invitation link was emailed to ' . $form['email'] . '.');
        } else {
            $this->setFlash('warning', $name . ' was added, but the invitation email could not be sent. Share this one-time link (valid 72 hours): ' . $link);
        }
        $this->redirect('users/index');
    }

    /**
     * Create a password_resets row and return the absolute reset URL
     * @param int $userId
     * @return string
     */
    private function issueResetLink($userId) {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db()->prepare(
            "INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user_id, :hash, DATE_ADD(NOW(), INTERVAL :ttl SECOND))"
        );
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':hash', hash('sha256', $token));
        $stmt->bindValue(':ttl', self::INVITE_TTL, PDO::PARAM_INT);
        $stmt->execute();

        $path = url('auth/reset/' . $token);
        if (strpos($path, 'http') !== 0) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
            $path = $scheme . '://' . $host . $path;
        }
        return $path;
    }

    /**
     * Invitation / reset email body
     * @param string $firstName
     * @param string|null $tempPassword
     * @param string|null $link
     * @return string HTML
     */
    private function inviteEmailHtml($firstName, $tempPassword, $link) {
        $html = '<p>Hi ' . e($firstName) . ',</p>';
        $html .= '<p>An account has been set up for you on SplashEstate CRM.</p>';
        if ($tempPassword !== null) {
            $html .= '<p>Your temporary password is: <strong>' . e($tempPassword) . '</strong><br>Please change it after your first login.</p>';
        }
        if ($link !== null) {
            $html .= '<p><a href="' . e($link) . '">Set your password</a> (this link is valid for 72 hours and can be used once).</p>';
        }
        return $html;
    }

    /**
     * Render the create/edit form
     */
    private function renderForm($title, $form, $errors, $user) {
        $tenants = array();
        if ($this->isPlatformAdmin() && $user === null && $this->getTenantId() === null) {
            $tenants = $this->db()->query("SELECT id, name FROM tenants ORDER BY name")->fetchAll();
        }
        $tenantId = $user !== null ? (int)$user['tenant_id'] : (!empty($form['tenant_id']) ? (int)$form['tenant_id'] : null);
        $this->view('users/form', array(
            'title' => $title,
            'form' => $form,
            'errors' => $errors,
            'user' => $user,
            'tenants' => $tenants,
            'roleOptions' => $this->roleOptions($tenantId),
            'canAssign' => $user === null ? can('roles.manage') : $this->canAssignRolesTo($user),
            'isSelf' => $user !== null && (int)$user['id'] === (int)$this->getUserId()
        ));
    }
}

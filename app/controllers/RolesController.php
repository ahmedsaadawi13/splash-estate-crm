<?php
// FILE: /app/controllers/RolesController.php

/**
 * SplashEstate CRM - Roles Controller (RBAC)
 * Lists system + tenant roles, shows role permissions, and manages custom
 * tenant roles. System roles are read-only. Requires roles.manage.
 *
 * Anti-escalation: a (non platform) user may only grant permissions they
 * hold themselves.
 */

class RolesController extends Controller {

    private $roleModel;
    private $permissionModel;

    public function __construct() {
        $this->roleModel = $this->model('Role');
        $this->permissionModel = $this->model('Permission');
    }

    /**
     * List roles with user counts
     */
    public function index() {
        $this->requirePermission('roles.manage');
        $tenantId = $this->scopeTenantId();

        $this->view('roles/index', array(
            'title' => 'Roles & Permissions',
            'roles' => $this->roleModel->getRolesForTenant($tenantId),
            'isPlatform' => $this->isPlatformAdmin()
        ));
    }

    /**
     * Role detail: permissions grouped by module and users (URL: roles/view/{id})
     * @param int $id Role ID
     */
    public function show($id) {
        $this->requirePermission('roles.manage');
        $role = $this->findRole($id);
        $tenantId = $this->scopeTenantId();

        $granted = $this->roleModel->getPermissionIds($role['id']);
        $this->view('roles/show', array(
            'title' => 'Role: ' . $role['name'],
            'role' => $role,
            'groupedPermissions' => $this->permissionModel->getGroupedByModule(),
            'granted' => $granted,
            'users' => $this->roleModel->getUsers($role['id'], $tenantId === null ? null : $tenantId),
            'editable' => $this->isEditable($role)
        ));
    }

    /**
     * Create custom role (GET form / POST save)
     */
    public function create() {
        $this->requirePermission('roles.manage');
        $tenantId = $this->getTenantId();
        if (empty($tenantId)) {
            $this->setFlash('info', 'Custom roles belong to an organization. Sign in as an organization admin to create one.');
            $this->redirect('roles/index');
        }

        $form = array('name' => '', 'description' => '', 'permissions' => array());
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('roles/create');
            $form = $this->readForm();
            $errors = $this->validateForm($form, $tenantId, null);
            if (empty($errors)) {
                $permissionIds = $this->filterGrantable($form['permissions'], array());
                $roleId = $this->roleModel->createCustomRole($tenantId, $form['name'], $form['description'] !== '' ? $form['description'] : null, $permissionIds);
                if ($roleId) {
                    logActivity($this->getUserId(), $tenantId, 'created', 'role', $roleId);
                    $this->setFlash('success', 'Role "' . $form['name'] . '" created.');
                    $this->redirect('roles/view/' . $roleId);
                }
                $errors['general'] = 'Could not create the role.';
            }
        }

        $this->renderForm('Create Role', $form, $errors, null);
    }

    /**
     * Edit custom role (GET form / POST save)
     * @param int $id Role ID
     */
    public function edit($id) {
        $this->requirePermission('roles.manage');
        $role = $this->findRole($id);
        if (!$this->isEditable($role)) {
            $this->setFlash('error', 'System roles are read-only.');
            $this->redirect('roles/view/' . (int)$role['id']);
        }
        $ownerTenant = (int)$role['tenant_id'];
        $existing = $this->roleModel->getPermissionIds($role['id']);

        $form = array('name' => $role['name'], 'description' => (string)$role['description'], 'permissions' => $existing);
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('roles/edit/' . (int)$role['id']);
            $form = $this->readForm();
            $errors = $this->validateForm($form, $ownerTenant, (int)$role['id']);
            if (empty($errors)) {
                $permissionIds = $this->filterGrantable($form['permissions'], $existing);
                $ok = $this->roleModel->updateCustomRole($role['id'], $ownerTenant, $form['name'], $form['description'] !== '' ? $form['description'] : null, $permissionIds);
                if ($ok) {
                    logActivity($this->getUserId(), $ownerTenant, 'updated', 'role', (int)$role['id']);
                    $this->setFlash('success', 'Role "' . $form['name'] . '" updated.');
                    $this->redirect('roles/view/' . (int)$role['id']);
                }
                $errors['general'] = 'Could not update the role.';
            }
        }

        $this->renderForm('Edit Role', $form, $errors, $role);
    }

    /**
     * Delete custom role (POST). Refused while users still hold it.
     * @param int $id Role ID
     */
    public function delete($id) {
        $this->requirePermission('roles.manage');
        $this->requirePostWithCsrf('roles/index');
        $role = $this->findRole($id);
        if (!$this->isEditable($role)) {
            $this->setFlash('error', 'System roles cannot be deleted.');
            $this->redirect('roles/index');
        }
        $userCount = $this->roleModel->countUsers($role['id']);
        if ($userCount > 0) {
            $this->setFlash('error', sprintf('Role "%s" is assigned to %d user(s). Change their roles first.', $role['name'], $userCount));
            $this->redirect('roles/view/' . (int)$role['id']);
        }
        if ($this->roleModel->deleteCustomRole($role['id'], (int)$role['tenant_id'])) {
            logActivity($this->getUserId(), (int)$role['tenant_id'], 'deleted', 'role', (int)$role['id']);
            $this->setFlash('success', 'Role "' . $role['name'] . '" deleted.');
        } else {
            $this->setFlash('error', 'Could not delete the role.');
        }
        $this->redirect('roles/index');
    }

    // ------------------------------------------------------------------

    /**
     * @return bool
     */
    private function isPlatformAdmin() {
        return $this->getUserRole() === 'platform_admin';
    }

    /**
     * Tenant scope for queries: null (everything) for platform admins without a tenant
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
     * Load a role visible to the current user or redirect
     * @param int $id
     * @return array
     */
    private function findRole($id) {
        $tenantId = $this->scopeTenantId();
        $role = $tenantId === null
            ? $this->roleModel->findById((int)$id)
            : $this->roleModel->getAccessibleRole((int)$id, $tenantId);
        if (!$role) {
            $this->setFlash('error', 'Role not found.');
            $this->redirect('roles/index');
        }
        return $role;
    }

    /**
     * Custom (non-system) roles owned by a tenant are editable
     * @param array $role
     * @return bool
     */
    private function isEditable($role) {
        return empty($role['is_system']) && $role['tenant_id'] !== null;
    }

    /**
     * Permission IDs the current user may grant (null = all)
     * @return array|null
     */
    private function grantablePermissionIds() {
        if ($this->isPlatformAdmin()) {
            return null;
        }
        $own = loadUserPermissions($this->getUserId());
        $ids = array();
        foreach ($this->permissionModel->getSlugMap() as $id => $slug) {
            if (in_array($slug, $own, true)) {
                $ids[] = (int)$id;
            }
        }
        return $ids;
    }

    /**
     * Keep only grantable permissions from the submission; permissions the
     * editor cannot grant keep their previous state.
     * @param array $submitted Submitted permission IDs
     * @param array $existing Current permission IDs of the role
     * @return array
     */
    private function filterGrantable($submitted, $existing) {
        $grantable = $this->grantablePermissionIds();
        $valid = array_keys($this->permissionModel->getSlugMap());
        $submitted = array_values(array_intersect(array_map('intval', $submitted), $valid));
        if ($grantable === null) {
            return $submitted;
        }
        $kept = array_diff(array_map('intval', $existing), $grantable);
        return array_values(array_unique(array_merge(array_intersect($submitted, $grantable), $kept)));
    }

    /**
     * @return array name, description, permissions (int[])
     */
    private function readForm() {
        $perms = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : array();
        return array(
            'name' => isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '',
            'description' => isset($_POST['description']) && is_string($_POST['description']) ? trim($_POST['description']) : '',
            'permissions' => array_values(array_filter(array_map('intval', $perms)))
        );
    }

    /**
     * @return array field => error
     */
    private function validateForm($form, $tenantId, $excludeId) {
        $errors = array();
        if ($form['name'] === '') {
            $errors['name'] = 'Name is required.';
        } elseif (strlen($form['name']) > 100) {
            $errors['name'] = 'Name must be at most 100 characters.';
        } elseif ($this->roleModel->nameExists($form['name'], $tenantId, $excludeId)) {
            $errors['name'] = 'A role with this name already exists.';
        }
        if (empty($form['permissions'])) {
            $errors['permissions'] = 'Select at least one permission.';
        }
        return $errors;
    }

    /**
     * Render create/edit form
     */
    private function renderForm($title, $form, $errors, $role) {
        $grantable = $this->grantablePermissionIds();
        $this->view('roles/form', array(
            'title' => $title,
            'form' => $form,
            'errors' => $errors,
            'role' => $role,
            'groupedPermissions' => $this->permissionModel->getGroupedByModule(),
            'grantable' => $grantable
        ));
    }
}

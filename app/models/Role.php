<?php
// FILE: /app/models/Role.php

/**
 * SplashEstate CRM - Role Model
 * Manages RBAC roles, their permissions and user-role assignments.
 *
 * System roles (tenant_id IS NULL, is_system = 1) are shared by every tenant
 * and are read-only. Custom roles belong to exactly one tenant.
 *
 * The inherited tenant-scoped helpers (getById/update/delete with a tenant ID)
 * only ever match custom roles of that tenant, which is what we want for
 * write operations. Read helpers that also include system roles use their
 * own names (getAccessibleRole, getRolesForTenant).
 */

class Role extends Model {

    protected $table = 'roles';

    /** Slug of the system role that grants full tenant administration */
    const TENANT_ADMIN_SLUG = 'tenant_admin';

    /** Slug of the system role for standard agents */
    const AGENT_SLUG = 'agent';

    /**
     * Roles available to a tenant (system roles + its custom roles) with the
     * number of the tenant's users holding each role.
     * @param int|null $tenantId Tenant ID; null = platform view (all roles, all users)
     * @return array
     */
    public function getRolesForTenant($tenantId) {
        if ($tenantId === null) {
            $sql = "SELECT r.*, t.name AS tenant_name,
                           (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count,
                           (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
                    FROM {$this->table} r
                    LEFT JOIN tenants t ON t.id = r.tenant_id
                    ORDER BY r.is_system DESC, t.name, r.name";
            $stmt = $this->conn->query($sql);
            return $stmt->fetchAll();
        }

        $sql = "SELECT r.*, NULL AS tenant_name,
                       (SELECT COUNT(*) FROM user_roles ur
                          INNER JOIN users u ON u.id = ur.user_id
                         WHERE ur.role_id = r.id AND u.tenant_id = :count_tenant) AS user_count,
                       (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
                FROM {$this->table} r
                WHERE r.tenant_id IS NULL OR r.tenant_id = :tenant_id
                ORDER BY r.is_system DESC, r.name";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':count_tenant', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get a role the tenant may see (a system role or one of its own roles)
     * @param int $id Role ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function getAccessibleRole($id, $tenantId) {
        $sql = "SELECT * FROM {$this->table}
                WHERE id = :id AND (tenant_id IS NULL OR tenant_id = :tenant_id)
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Get role by slug (system role, or tenant role when a tenant is given)
     * @param string $slug Role slug
     * @param int|null $tenantId Tenant ID
     * @return array|false Role data
     */
    public function getBySlug($slug, $tenantId = null) {
        if ($tenantId === null) {
            $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE slug = :slug AND tenant_id IS NULL LIMIT 1");
            $stmt->execute(array(':slug' => $slug));
        } else {
            // Prefer the tenant's own role when slugs collide with a system role
            $stmt = $this->conn->prepare(
                "SELECT * FROM {$this->table}
                 WHERE slug = :slug AND (tenant_id IS NULL OR tenant_id = :tenant_id)
                 ORDER BY tenant_id IS NULL ASC
                 LIMIT 1"
            );
            $stmt->execute(array(':slug' => $slug, ':tenant_id' => (int)$tenantId));
        }
        return $stmt->fetch();
    }

    /**
     * Get permissions for a role
     * @param int $roleId Role ID
     * @return array Permissions
     */
    public function getPermissions($roleId) {
        $sql = "SELECT p.*
                FROM permissions p
                INNER JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = :role_id
                ORDER BY p.module, p.name";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array(':role_id' => (int)$roleId));
        return $stmt->fetchAll();
    }

    /**
     * Get permission slugs for a role
     * @param int $roleId Role ID
     * @return array Permission slugs
     */
    public function getPermissionSlugs($roleId) {
        return array_column($this->getPermissions($roleId), 'slug');
    }

    /**
     * Get permission IDs for a role
     * @param int $roleId Role ID
     * @return array<int>
     */
    public function getPermissionIds($roleId) {
        return array_map('intval', array_column($this->getPermissions($roleId), 'id'));
    }

    /**
     * Check if role has permission
     * @param int $roleId Role ID
     * @param string $permissionSlug Permission slug
     * @return bool Has permission
     */
    public function hasPermission($roleId, $permissionSlug) {
        $sql = "SELECT COUNT(*) FROM role_permissions rp
                INNER JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = :role_id AND p.slug = :slug";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array(':role_id' => (int)$roleId, ':slug' => $permissionSlug));
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Create role. Custom roles must belong to a tenant.
     * @param array $data tenant_id, name, slug, description, is_system
     * @return int|false Role ID
     */
    public function create($data) {
        $sql = "INSERT INTO {$this->table} (tenant_id, name, slug, description, is_system)
                VALUES (:tenant_id, :name, :slug, :description, :is_system)";
        $stmt = $this->conn->prepare($sql);
        $result = $stmt->execute(array(
            ':tenant_id' => isset($data['tenant_id']) ? $data['tenant_id'] : null,
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':description' => isset($data['description']) ? $data['description'] : null,
            ':is_system' => !empty($data['is_system']) ? 1 : 0
        ));
        return $result ? (int)$this->conn->lastInsertId() : false;
    }

    /**
     * Create a custom tenant role together with its permissions
     * @param int $tenantId Tenant ID
     * @param string $name Role name
     * @param string|null $description Description
     * @param array $permissionIds Permission IDs
     * @return int|false Role ID
     */
    public function createCustomRole($tenantId, $name, $description, $permissionIds) {
        if (empty($tenantId)) {
            return false;
        }
        $started = false;
        try {
            $started = $this->beginTx();
            $roleId = $this->create(array(
                'tenant_id' => (int)$tenantId,
                'name' => $name,
                'slug' => $this->uniqueSlug($name, $tenantId),
                'description' => $description,
                'is_system' => 0
            ));
            if (!$roleId) {
                $this->rollbackTx($started);
                return false;
            }
            $this->replacePermissions($roleId, $permissionIds);
            $this->commitTx($started);
            return $roleId;
        } catch (Exception $e) {
            if (!$started) {
                throw $e; // let the caller's transaction roll back
            }
            $this->rollbackTx($started);
            error_log('Role::createCustomRole failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a custom role of a tenant (system roles never match) and its permissions
     * @param int $id Role ID
     * @param int $tenantId Owning tenant ID
     * @param string $name Role name
     * @param string|null $description Description
     * @param array $permissionIds Permission IDs
     * @return bool
     */
    public function updateCustomRole($id, $tenantId, $name, $description, $permissionIds) {
        $role = $this->getById($id, $tenantId);
        if (!$role || !empty($role['is_system'])) {
            return false;
        }
        $started = false;
        try {
            $started = $this->beginTx();
            $data = array('name' => $name, 'description' => $description);
            if ($name !== $role['name']) {
                $data['slug'] = $this->uniqueSlug($name, $tenantId, $id);
            }
            $this->update($id, $tenantId, $data);
            $this->replacePermissions($id, $permissionIds);
            $this->commitTx($started);
            return true;
        } catch (Exception $e) {
            if (!$started) {
                throw $e; // let the caller's transaction roll back
            }
            $this->rollbackTx($started);
            error_log('Role::updateCustomRole failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a custom role of a tenant. System roles cannot be deleted.
     * @param int $id Role ID
     * @param int $tenantId Owning tenant ID
     * @return bool
     */
    public function deleteCustomRole($id, $tenantId) {
        $role = $this->getById($id, $tenantId);
        if (!$role || !empty($role['is_system'])) {
            return false;
        }
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE id = :id AND tenant_id = :tenant_id AND is_system = 0");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Begin a transaction unless the shared connection already has one
     * (Database shares one PDO per request, so callers may wrap us).
     * @return bool Whether this call started it
     */
    private function beginTx() {
        if ($this->conn->inTransaction()) {
            return false;
        }
        $this->conn->beginTransaction();
        return true;
    }

    /** @param bool $started */
    private function commitTx($started) {
        if ($started) {
            $this->conn->commit();
        }
    }

    /** @param bool $started */
    private function rollbackTx($started) {
        if ($started && $this->conn->inTransaction()) {
            $this->conn->rollBack();
        }
    }

    /**
     * Replace the permissions of a (custom) role. Unknown permission IDs are ignored.
     * Callers must have verified the role is a custom role they may modify.
     * @param int $roleId Role ID
     * @param array $permissionIds Permission IDs
     * @return void
     */
    private function replacePermissions($roleId, $permissionIds) {
        $del = $this->conn->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
        $del->execute(array(':role_id' => (int)$roleId));

        $ids = array_unique(array_filter(array_map('intval', (array)$permissionIds)));
        if (empty($ids)) {
            return;
        }
        // INSERT ... SELECT silently skips IDs that are not real permissions
        $ins = $this->conn->prepare(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT :role_id, id FROM permissions WHERE id = :permission_id"
        );
        foreach ($ids as $permissionId) {
            $ins->execute(array(':role_id' => (int)$roleId, ':permission_id' => $permissionId));
        }
    }

    /**
     * Build a slug unique among the roles visible to a tenant
     * @param string $name Role name
     * @param int $tenantId Tenant ID
     * @param int|null $excludeId Role ID to ignore (when renaming)
     * @return string
     */
    private function uniqueSlug($name, $tenantId, $excludeId = null) {
        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));
        if ($base === '') {
            $base = 'role';
        }
        $base = substr($base, 0, 80);
        $slug = $base;
        $i = 2;
        while ($this->slugTaken($slug, $tenantId, $excludeId)) {
            $slug = $base . '_' . $i;
            $i++;
        }
        return $slug;
    }

    /**
     * @param string $slug
     * @param int $tenantId
     * @param int|null $excludeId
     * @return bool
     */
    private function slugTaken($slug, $tenantId, $excludeId) {
        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE slug = :slug AND (tenant_id IS NULL OR tenant_id = :tenant_id)";
        if ($excludeId !== null) {
            $sql .= " AND id <> :exclude_id";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        if ($excludeId !== null) {
            $stmt->bindValue(':exclude_id', (int)$excludeId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Whether a role name is already used by a role visible to the tenant
     * @param string $name Role name
     * @param int $tenantId Tenant ID
     * @param int|null $excludeId Role ID to ignore
     * @return bool
     */
    public function nameExists($name, $tenantId, $excludeId = null) {
        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE LOWER(name) = LOWER(:name) AND (tenant_id IS NULL OR tenant_id = :tenant_id)";
        if ($excludeId !== null) {
            $sql .= " AND id <> :exclude_id";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        if ($excludeId !== null) {
            $stmt->bindValue(':exclude_id', (int)$excludeId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Users of a tenant that hold a role
     * @param int $roleId Role ID
     * @param int|null $tenantId Tenant ID; null = all tenants (platform view)
     * @return array Users (no password hashes)
     */
    public function getUsers($roleId, $tenantId = null) {
        $sql = "SELECT u.id, u.tenant_id, u.first_name, u.last_name, u.email, u.role, u.status
                FROM users u
                INNER JOIN user_roles ur ON u.id = ur.user_id
                WHERE ur.role_id = :role_id";
        if ($tenantId !== null) {
            $sql .= " AND u.tenant_id = :tenant_id";
        }
        $sql .= " ORDER BY u.first_name, u.last_name";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':role_id', (int)$roleId, PDO::PARAM_INT);
        if ($tenantId !== null) {
            $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count users (any tenant) holding a role
     * @param int $roleId Role ID
     * @return int
     */
    public function countUsers($roleId) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id");
        $stmt->execute(array(':role_id' => (int)$roleId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * Fetch a user that belongs to the tenant
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    private function getTenantUser($userId, $tenantId) {
        if (empty($tenantId)) {
            return false;
        }
        $stmt = $this->conn->prepare("SELECT id, tenant_id, role, status FROM users WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
        $stmt->bindValue(':id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Assign a role to a user. The user must belong to $tenantId and the role
     * must be a system role or a custom role of the same tenant.
     * @param int $userId User ID
     * @param int $roleId Role ID
     * @param int $tenantId Tenant the user belongs to
     * @return bool Success
     */
    public function assignToUser($userId, $roleId, $tenantId) {
        if (!$this->getTenantUser($userId, $tenantId) || !$this->getAccessibleRole($roleId, $tenantId)) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)"
        );
        $ok = $stmt->execute(array(':user_id' => (int)$userId, ':role_id' => (int)$roleId));
        if ($ok) {
            $this->syncLegacyRole($userId, $tenantId);
        }
        return $ok;
    }

    /**
     * Remove a role from a user. Refuses to remove the last tenant admin.
     * @param int $userId User ID
     * @param int $roleId Role ID
     * @param int $tenantId Tenant the user belongs to
     * @return bool Success
     */
    public function removeFromUser($userId, $roleId, $tenantId) {
        if (!$this->getTenantUser($userId, $tenantId) || !$this->getAccessibleRole($roleId, $tenantId)) {
            return false;
        }
        $current = array_map('intval', array_column($this->getUserRoles($userId), 'id'));
        $remaining = array_values(array_diff($current, array((int)$roleId)));
        if ($this->wouldRemoveLastAdmin($userId, $tenantId, $remaining)) {
            return false;
        }
        $stmt = $this->conn->prepare("DELETE FROM user_roles WHERE user_id = :user_id AND role_id = :role_id");
        $ok = $stmt->execute(array(':user_id' => (int)$userId, ':role_id' => (int)$roleId));
        if ($ok) {
            $this->syncLegacyRole($userId, $tenantId);
        }
        return $ok;
    }

    /**
     * Replace all roles of a user (validated against the tenant).
     * Also keeps the legacy users.role column consistent.
     * @param int $userId User ID
     * @param array $roleIds Role IDs (must be accessible to the tenant)
     * @param int $tenantId Tenant the user belongs to
     * @return bool|string true on success, or an error message
     */
    public function setUserRoles($userId, $roleIds, $tenantId) {
        if (!$this->getTenantUser($userId, $tenantId)) {
            return 'User not found in this organization.';
        }
        $roleIds = array_values(array_unique(array_filter(array_map('intval', (array)$roleIds))));
        if (empty($roleIds)) {
            return 'Select at least one role.';
        }
        foreach ($roleIds as $roleId) {
            if (!$this->getAccessibleRole($roleId, $tenantId)) {
                return 'Invalid role selected.';
            }
        }
        if ($this->wouldRemoveLastAdmin($userId, $tenantId, $roleIds)) {
            return 'This is the last Tenant Admin of the organization; assign the Tenant Admin role to another active user first.';
        }

        $started = false;
        try {
            $started = $this->beginTx();
            $del = $this->conn->prepare("DELETE FROM user_roles WHERE user_id = :user_id");
            $del->execute(array(':user_id' => (int)$userId));
            $ins = $this->conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)");
            foreach ($roleIds as $roleId) {
                $ins->execute(array(':user_id' => (int)$userId, ':role_id' => $roleId));
            }
            $this->syncLegacyRole($userId, $tenantId);
            $this->commitTx($started);
            return true;
        } catch (Exception $e) {
            if (!$started) {
                throw $e; // let the caller's transaction roll back
            }
            $this->rollbackTx($started);
            error_log('Role::setUserRoles failed: ' . $e->getMessage());
            return 'Could not update roles.';
        }
    }

    /**
     * Whether a user is (effectively) a tenant admin: holds the Tenant Admin
     * system role, or has no RBAC roles and users.role = tenant_admin.
     * @param int $userId User ID
     * @return bool
     */
    public function isTenantAdmin($userId) {
        $roles = $this->getUserRoles($userId);
        if (!empty($roles)) {
            foreach ($roles as $role) {
                if ($role['slug'] === self::TENANT_ADMIN_SLUG && $role['tenant_id'] === null) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $this->conn->prepare("SELECT role FROM users WHERE id = :id");
        $stmt->execute(array(':id' => (int)$userId));
        return $stmt->fetchColumn() === 'tenant_admin';
    }

    /**
     * Number of active tenant admins of a tenant (see isTenantAdmin())
     * @param int $tenantId Tenant ID
     * @param int|null $excludeUserId User to leave out of the count
     * @return int
     */
    public function countActiveTenantAdmins($tenantId, $excludeUserId = null) {
        $sql = "SELECT COUNT(*) FROM users u
                WHERE u.tenant_id = :tenant_id AND u.status = 'active'
                  AND (
                    EXISTS (SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id
                             WHERE ur.user_id = u.id AND r.slug = :slug AND r.tenant_id IS NULL)
                    OR (u.role = 'tenant_admin' AND NOT EXISTS (SELECT 1 FROM user_roles ur2 WHERE ur2.user_id = u.id))
                  )";
        if ($excludeUserId !== null) {
            $sql .= " AND u.id <> :exclude_id";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':slug', self::TENANT_ADMIN_SLUG);
        if ($excludeUserId !== null) {
            $stmt->bindValue(':exclude_id', (int)$excludeUserId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Whether giving a user exactly $newRoleIds would leave the tenant with
     * no active tenant admin.
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @param array $newRoleIds Role IDs the user would end up with
     * @return bool
     */
    public function wouldRemoveLastAdmin($userId, $tenantId, $newRoleIds) {
        if (!$this->isTenantAdmin($userId)) {
            return false;
        }
        $admin = $this->getBySlug(self::TENANT_ADMIN_SLUG);
        if ($admin && in_array((int)$admin['id'], array_map('intval', $newRoleIds), true)) {
            return false;
        }
        return $this->countActiveTenantAdmins($tenantId, $userId) === 0;
    }

    /**
     * Keep users.role (legacy checks such as requireRole('tenant_admin'))
     * consistent with RBAC: tenant_admin when the user holds the Tenant Admin
     * system role, otherwise agent. Platform admins are never touched.
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @return void
     */
    private function syncLegacyRole($userId, $tenantId) {
        $legacy = 'agent';
        foreach ($this->getUserRoles($userId) as $role) {
            if ($role['slug'] === self::TENANT_ADMIN_SLUG && $role['tenant_id'] === null) {
                $legacy = 'tenant_admin';
                break;
            }
        }
        $stmt = $this->conn->prepare(
            "UPDATE users SET role = :role
             WHERE id = :id AND tenant_id = :tenant_id AND role <> 'platform_admin'"
        );
        $stmt->execute(array(':role' => $legacy, ':id' => (int)$userId, ':tenant_id' => (int)$tenantId));
    }

    /**
     * Get user's roles
     * @param int $userId User ID
     * @return array Roles
     */
    public function getUserRoles($userId) {
        $sql = "SELECT r.*
                FROM roles r
                INNER JOIN user_roles ur ON r.id = ur.role_id
                WHERE ur.user_id = :user_id
                ORDER BY r.name";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array(':user_id' => (int)$userId));
        return $stmt->fetchAll();
    }

    /**
     * Roles of every user in a tenant, keyed by user ID
     * @param int|null $tenantId Tenant ID; null = all users (platform view)
     * @return array user_id => array of roles
     */
    public function getRolesByUser($tenantId) {
        $sql = "SELECT ur.user_id, r.id, r.name, r.slug, r.tenant_id, r.is_system
                FROM user_roles ur
                INNER JOIN roles r ON r.id = ur.role_id
                INNER JOIN users u ON u.id = ur.user_id";
        if ($tenantId !== null) {
            $sql .= " WHERE u.tenant_id = :tenant_id";
        }
        $sql .= " ORDER BY r.name";
        $stmt = $this->conn->prepare($sql);
        if ($tenantId !== null) {
            $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $map = array();
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['user_id']][] = $row;
        }
        return $map;
    }

    /**
     * Get all permissions for a user (from all their roles)
     * @param int $userId User ID
     * @return array Permission slugs
     */
    public function getUserPermissions($userId) {
        $sql = "SELECT DISTINCT p.slug
                FROM permissions p
                INNER JOIN role_permissions rp ON p.id = rp.permission_id
                INNER JOIN user_roles ur ON rp.role_id = ur.role_id
                WHERE ur.user_id = :user_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array(':user_id' => (int)$userId));
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

<?php
// FILE: /app/models/Permission.php

/**
 * SplashEstate CRM - Permission Model
 * Global (not tenant-scoped) list of permission slugs used by RBAC.
 */

class Permission extends Model {

    protected $table = 'permissions';

    /**
     * Get every permission ordered by module and name.
     * (Named differently from Model::getAll(), which is tenant-scoped.)
     * @return array Permissions
     */
    public function getAllPermissions() {
        $stmt = $this->conn->query("SELECT * FROM {$this->table} ORDER BY module, name");
        return $stmt->fetchAll();
    }

    /**
     * Get permissions by module
     * @param string $module Module name
     * @return array Permissions
     */
    public function getByModule($module) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE module = :module ORDER BY name");
        $stmt->execute(array(':module' => $module));
        return $stmt->fetchAll();
    }

    /**
     * Get permission by slug
     * @param string $slug Permission slug
     * @return array|false Permission data
     */
    public function getBySlug($slug) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE slug = :slug LIMIT 1");
        $stmt->execute(array(':slug' => $slug));
        return $stmt->fetch();
    }

    /**
     * Get permissions grouped by module
     * @param array|null $permissions Optional pre-filtered list (defaults to all permissions)
     * @return array module => permissions
     */
    public function getGroupedByModule($permissions = null) {
        if ($permissions === null) {
            $permissions = $this->getAllPermissions();
        }
        $grouped = array();
        foreach ($permissions as $permission) {
            $module = !empty($permission['module']) ? $permission['module'] : 'general';
            if (!isset($grouped[$module])) {
                $grouped[$module] = array();
            }
            $grouped[$module][] = $permission;
        }
        return $grouped;
    }

    /**
     * Map of permission ID => slug
     * @return array
     */
    public function getSlugMap() {
        $map = array();
        foreach ($this->getAllPermissions() as $permission) {
            $map[(int)$permission['id']] = $permission['slug'];
        }
        return $map;
    }

    /**
     * Create permission
     * @param array $data Permission data
     * @return int|false Permission ID
     */
    public function create($data) {
        $sql = "INSERT INTO {$this->table} (name, slug, description, module)
                VALUES (:name, :slug, :description, :module)";
        $stmt = $this->conn->prepare($sql);
        $result = $stmt->execute(array(
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':description' => isset($data['description']) ? $data['description'] : null,
            ':module' => isset($data['module']) ? $data['module'] : null
        ));
        return $result ? (int)$this->conn->lastInsertId() : false;
    }

    /**
     * Check if permission exists by slug
     * @param string $slug Permission slug
     * @return bool Exists
     */
    public function existsBySlug($slug) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} WHERE slug = :slug");
        $stmt->execute(array(':slug' => $slug));
        return (int)$stmt->fetchColumn() > 0;
    }
}

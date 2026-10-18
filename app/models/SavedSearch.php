<?php
// FILE: /app/models/SavedSearch.php

/**
 * SplashEstate CRM - Saved Search Model
 * Per-user saved list filters. Every query is scoped by user_id AND tenant_id.
 *
 * Methods intentionally use their own names (createSearch, findForUser, ...)
 * instead of overriding the generic Model CRUD methods, whose semantics differ.
 */

require_once APP_PATH . '/helpers/SearchHelper.php';

class SavedSearch extends Model {

    protected $table = 'saved_searches';

    const MAX_PER_MODULE = 50;

    /** Modules that support saved searches */
    public static $modules = array('leads', 'clients', 'properties', 'deals', 'tasks');

    /**
     * @param mixed $module
     * @return bool
     */
    public static function isValidModule($module) {
        return is_string($module) && in_array($module, self::$modules, true);
    }

    /**
     * Keep only allowed, scalar, reasonably sized filter values
     * @param mixed $filters
     * @param string $module
     * @return array
     */
    public static function sanitizeFilters($filters, $module) {
        if (!is_array($filters)) {
            return array();
        }
        $allowed = SearchHelper::allowedFilterKeys($module);
        $clean = array();
        foreach ($filters as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value === '' || strlen($value) > 255) {
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    /**
     * Saved searches for a user (optionally one module)
     * @param int $userId
     * @param int $tenantId
     * @param string|null $module
     * @return array
     */
    public function getUserSearches($userId, $tenantId, $module = null) {
        $sql = "SELECT id, module, name, filters, is_default, created_at, updated_at
                FROM {$this->table}
                WHERE user_id = :user_id AND tenant_id = :tenant_id";
        if ($module !== null) {
            $sql .= " AND module = :module";
        }
        $sql .= " ORDER BY is_default DESC, name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        if ($module !== null) {
            $stmt->bindValue(':module', $module);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['is_default'] = (int)$row['is_default'];
            $row['filters'] = $this->parseFilters($row['filters']);
        }
        unset($row);
        return $rows;
    }

    /**
     * One saved search owned by the user in the tenant
     * @param int $id
     * @param int $userId
     * @param int $tenantId
     * @return array|false
     */
    public function findForUser($id, $userId, $tenantId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM {$this->table}
             WHERE id = :id AND user_id = :user_id AND tenant_id = :tenant_id LIMIT 1"
        );
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Number of saved searches a user has for a module
     * @return int
     */
    public function countForUser($userId, $tenantId, $module) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE user_id = :user_id AND tenant_id = :tenant_id AND module = :module"
        );
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':module', $module);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Create a saved search
     * @param int $tenantId
     * @param int $userId
     * @param string $module Validated module
     * @param string $name
     * @param array $filters Sanitized filters
     * @param bool $isDefault
     * @return int|false New id
     */
    public function createSearch($tenantId, $userId, $module, $name, $filters, $isDefault = false) {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} (tenant_id, user_id, module, name, filters, is_default)
             VALUES (:tenant_id, :user_id, :module, :name, :filters, 0)"
        );
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':module', $module);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filters', json_encode((object)$filters));
        if (!$stmt->execute()) {
            return false;
        }
        $id = (int)$this->conn->lastInsertId();
        if ($isDefault) {
            $this->setAsDefault($id, $userId, $tenantId, $module);
        }
        return $id;
    }

    /**
     * Update name/filters of a saved search
     * @return bool
     */
    public function updateSearch($id, $userId, $tenantId, $name, $filters) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET name = :name, filters = :filters
             WHERE id = :id AND user_id = :user_id AND tenant_id = :tenant_id"
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filters', json_encode((object)$filters));
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Delete a saved search owned by the user
     * @return bool True if a row was deleted
     */
    public function deleteForUser($id, $userId, $tenantId) {
        $stmt = $this->conn->prepare(
            "DELETE FROM {$this->table} WHERE id = :id AND user_id = :user_id AND tenant_id = :tenant_id"
        );
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Make one search the user's default for its module (unsets the others)
     * @return bool
     */
    public function setAsDefault($id, $userId, $tenantId, $module) {
        try {
            $this->conn->beginTransaction();
            $stmt = $this->conn->prepare(
                "UPDATE {$this->table} SET is_default = 0
                 WHERE user_id = :user_id AND tenant_id = :tenant_id AND module = :module"
            );
            $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
            $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':module', $module);
            $stmt->execute();

            $stmt = $this->conn->prepare(
                "UPDATE {$this->table} SET is_default = 1
                 WHERE id = :id AND user_id = :user_id AND tenant_id = :tenant_id"
            );
            $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
            $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
            $stmt->execute();
            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Set default search error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear the default flag of a search
     * @return bool
     */
    public function clearDefault($id, $userId, $tenantId) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET is_default = 0 WHERE id = :id AND user_id = :user_id AND tenant_id = :tenant_id"
        );
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * The user's default search for a module
     * @return array|false
     */
    public function getDefault($userId, $tenantId, $module) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = :user_id AND tenant_id = :tenant_id AND module = :module AND is_default = 1
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':module', $module);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Decode stored filters
     * @param string $filtersJson
     * @return array
     */
    public function parseFilters($filtersJson) {
        $filters = json_decode((string)$filtersJson, true);
        return is_array($filters) ? $filters : array();
    }
}

<?php
// FILE: /app/models/Tenant.php

/**
 * SplashEstate CRM - Tenant Model
 * Handles tenant/agency management
 */

class Tenant extends Model {

    protected $table = 'tenants';

    /**
     * Create tenant
     * @param array $data Tenant data
     * @return int|bool Tenant ID or false
     */
    public function create($data) {
        $sql = "INSERT INTO {$this->table} (name, slug, email, phone, address, api_key, status, created_at)
                VALUES (:name, :slug, :email, :phone, :address, :api_key, :status, NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':slug', $data['slug']);
        $stmt->bindValue(':email', $data['email']);
        $stmt->bindValue(':phone', isset($data['phone']) ? $data['phone'] : null);
        $stmt->bindValue(':address', isset($data['address']) ? $data['address'] : null);
        $stmt->bindValue(':api_key', $data['api_key']);
        $stmt->bindValue(':status', isset($data['status']) ? $data['status'] : 'active');

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    /**
     * Get tenant by ID.
     * (Named findTenant because Model::getById($id, $tenantId) has a
     * different, tenant-scoped signature; tenants have no tenant_id column.)
     * @param int $id Tenant ID
     * @return array|false Tenant data or false
     */
    public function findTenant($id) {
        return $this->findById($id);
    }

    /**
     * Update tenant
     * @param int $id Tenant ID
     * @param array $data Updated data
     * @return bool Success status
     */
    public function updateTenant($id, $data) {
        $sql = "UPDATE {$this->table}
                SET name = :name,
                    email = :email,
                    phone = :phone,
                    address = :address,
                    updated_at = NOW()
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':email', $data['email']);
        $stmt->bindValue(':phone', isset($data['phone']) ? $data['phone'] : null);
        $stmt->bindValue(':address', isset($data['address']) ? $data['address'] : null);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Get all tenants (platform admin only)
     * @return array Tenants
     */
    public function getAllTenants() {
        $sql = "SELECT * FROM {$this->table} ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Check if slug exists
     * @param string $slug Slug to check
     * @return bool
     */
    public function slugExists($slug) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE slug = :slug";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':slug', $slug);
        $stmt->execute();
        $result = $stmt->fetch();
        return (int)$result['count'] > 0;
    }

    /**
     * Regenerate API key
     * @param int $id Tenant ID
     * @return string New API key
     */
    public function regenerateApiKey($id) {
        $newApiKey = generateApiKey();

        $sql = "UPDATE {$this->table} SET api_key = :api_key, updated_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':api_key', $newApiKey);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return $newApiKey;
        }

        return false;
    }
}

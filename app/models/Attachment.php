<?php
// FILE: /app/models/Attachment.php

/**
 * SplashEstate CRM - Attachment Model
 * Handles file attachments
 */

class Attachment extends Model {

    protected $table = 'attachments';

    /**
     * Get attachments by related entity
     * @param int $tenantId Tenant ID
     * @param string $type Related entity type
     * @param int $relatedId Related entity ID
     * @return array Attachments
     */
    public function getByRelated($tenantId, $type, $relatedId) {
        $sql = "SELECT a.*,
                CONCAT(u.first_name, ' ', u.last_name) as uploaded_by
                FROM {$this->table} a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.tenant_id = :tenant_id
                AND a.related_to_type = :type
                AND a.related_to_id = :related_id
                ORDER BY a.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':related_id', $relatedId, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Create attachment
     * @param array $data Attachment data
     * @return int|bool Attachment ID or false
     */
    public function createAttachment($data) {
        $sql = "INSERT INTO {$this->table}
                (tenant_id, user_id, related_to_type, related_to_id, file_name, file_path, file_size, file_type, created_at)
                VALUES (:tenant_id, :user_id, :related_to_type, :related_to_id, :file_name, :file_path, :file_size, :file_type, NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', $data['tenant_id'], PDO::PARAM_INT);
        $stmt->bindValue(':user_id', isset($data['user_id']) ? $data['user_id'] : null, PDO::PARAM_INT);
        $stmt->bindValue(':related_to_type', $data['related_to_type']);
        $stmt->bindValue(':related_to_id', $data['related_to_id'], PDO::PARAM_INT);
        $stmt->bindValue(':file_name', $data['file_name']);
        $stmt->bindValue(':file_path', $data['file_path']);
        $stmt->bindValue(':file_size', isset($data['file_size']) ? $data['file_size'] : null, PDO::PARAM_INT);
        $stmt->bindValue(':file_type', isset($data['file_type']) ? $data['file_type'] : null);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    /**
     * Delete attachment
     * @param int $id Attachment ID
     * @param int $tenantId Tenant ID
     * @return bool Success status
     */
    public function deleteAttachment($id, $tenantId) {
        // Get attachment info first
        $attachment = $this->getById($id, $tenantId);

        if ($attachment) {
            // Delete file
            deleteFile($attachment['file_path']);

            // Delete record
            return $this->delete($id, $tenantId);
        }

        return false;
    }

    /**
     * Whether the record an attachment would belong to exists in the tenant
     * @param int $tenantId Tenant ID
     * @param string $type lead|client|property|deal|task
     * @param int $relatedId Record ID
     * @return bool
     */
    public function relatedExists($tenantId, $type, $relatedId) {
        $tables = array('lead' => 'leads', 'client' => 'clients', 'property' => 'properties', 'deal' => 'deals', 'task' => 'tasks');
        if (!isset($tables[$type]) || (int)$relatedId <= 0) {
            return false;
        }
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM `{$tables[$type]}` WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->bindValue(':id', (int)$relatedId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }
}

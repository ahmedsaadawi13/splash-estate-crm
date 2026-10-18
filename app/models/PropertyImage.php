<?php
// FILE: /app/models/PropertyImage.php

/**
 * SplashEstate CRM - Property Image Model
 * Manages property images for the gallery.
 *
 * file_path is stored relative to UPLOAD_PATH, e.g.
 * "{tenantId}/properties/{propertyId}/{random}.jpg". Files live outside the
 * web root and are served by PropertyGalleryController::image($id).
 */

class PropertyImage extends Model {

    protected $table = 'property_images';

    /**
     * Get all images for a property
     * @param int $propertyId Property ID
     * @param int $tenantId Tenant ID
     * @return array Images ordered by primary flag, display_order
     */
    public function getPropertyImages($propertyId, $tenantId) {
        $sql = "SELECT pi.id, pi.property_id, pi.file_name, pi.file_size, pi.mime_type, pi.is_primary,
                       pi.display_order, pi.caption, pi.created_at,
                       CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
                FROM {$this->table} pi
                LEFT JOIN users u ON pi.uploaded_by = u.id
                WHERE pi.property_id = :property_id AND pi.tenant_id = :tenant_id
                ORDER BY pi.is_primary DESC, pi.display_order ASC, pi.id ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':property_id', (int)$propertyId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get primary image for a property
     * @param int $propertyId Property ID
     * @param int $tenantId Tenant ID
     * @return array|false Primary image data
     */
    public function getPrimaryImage($propertyId, $tenantId) {
        $sql = "SELECT * FROM {$this->table}
                WHERE property_id = :property_id AND tenant_id = :tenant_id
                ORDER BY is_primary DESC, display_order ASC, id ASC
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':property_id', (int)$propertyId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Add property image
     * @param array $data Image data
     * @return int|false Image ID or false
     */
    public function addImage($data) {
        $row = array(
            'tenant_id' => (int)$data['tenant_id'],
            'property_id' => (int)$data['property_id'],
            'file_name' => (string)$data['file_name'],
            'file_path' => (string)$data['file_path'],
            'file_size' => isset($data['file_size']) ? (int)$data['file_size'] : null,
            'mime_type' => isset($data['mime_type']) ? $data['mime_type'] : null,
            'is_primary' => !empty($data['is_primary']) ? 1 : 0,
            'display_order' => isset($data['display_order']) ? (int)$data['display_order'] : 0,
            'caption' => isset($data['caption']) ? $data['caption'] : null,
            'uploaded_by' => isset($data['uploaded_by']) ? (int)$data['uploaded_by'] : null
        );
        try {
            $id = $this->create($row);
            return $id ? (int)$id : false;
        } catch (PDOException $e) {
            error_log('Add property image error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set primary image (only if the image belongs to the property and tenant)
     * @param int $imageId Image ID
     * @param int $propertyId Property ID
     * @param int $tenantId Tenant ID
     * @return bool Success status
     */
    public function setPrimaryImage($imageId, $propertyId, $tenantId) {
        $image = $this->getById($imageId, $tenantId);
        if (!$image || (int)$image['property_id'] !== (int)$propertyId) {
            return false;
        }

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare(
                "UPDATE {$this->table} SET is_primary = 0 WHERE property_id = :property_id AND tenant_id = :tenant_id"
            );
            $stmt->bindValue(':property_id', (int)$propertyId, PDO::PARAM_INT);
            $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
            $stmt->execute();

            $stmt = $this->conn->prepare(
                "UPDATE {$this->table} SET is_primary = 1 WHERE id = :id AND property_id = :property_id AND tenant_id = :tenant_id"
            );
            $stmt->bindValue(':id', (int)$imageId, PDO::PARAM_INT);
            $stmt->bindValue(':property_id', (int)$propertyId, PDO::PARAM_INT);
            $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
            $stmt->execute();

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Set primary image error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update display order of an image of a given property
     * @param int $imageId Image ID
     * @param int $order New order
     * @param int $tenantId Tenant ID
     * @param int|null $propertyId Restrict to this property
     * @return bool Success status (false if the image is not found)
     */
    public function updateOrder($imageId, $order, $tenantId, $propertyId = null) {
        $sql = "UPDATE {$this->table} SET display_order = :display_order WHERE id = :id AND tenant_id = :tenant_id";
        if ($propertyId !== null) {
            $sql .= " AND property_id = :property_id";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':display_order', (int)$order, PDO::PARAM_INT);
        $stmt->bindValue(':id', (int)$imageId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        if ($propertyId !== null) {
            $stmt->bindValue(':property_id', (int)$propertyId, PDO::PARAM_INT);
        }
        return $stmt->execute();
    }

    /**
     * Absolute filesystem path for a stored image, or null if it would escape UPLOAD_PATH
     * @param array $image Image row
     * @return string|null
     */
    public static function absolutePath($image) {
        if (empty($image['file_path'])) {
            return null;
        }
        $relative = ltrim(str_replace('\\', '/', (string)$image['file_path']), '/');
        if (strpos($relative, '..') !== false) {
            return null;
        }
        $base = realpath(UPLOAD_PATH);
        if ($base === false) {
            return null;
        }
        $path = realpath($base . '/' . $relative);
        if ($path === false || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $path;
    }

    /**
     * Delete image (row + file). If it was the primary image, the next image is promoted.
     * @param int $imageId Image ID
     * @param int $tenantId Tenant ID
     * @return bool Success status
     */
    public function deleteImage($imageId, $tenantId) {
        $image = $this->getById($imageId, $tenantId);
        if (!$image) {
            return false;
        }

        if (!$this->delete($imageId, $tenantId)) {
            return false;
        }

        $path = self::absolutePath($image);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }

        if (!empty($image['is_primary'])) {
            $next = $this->getPrimaryImage($image['property_id'], $tenantId);
            if ($next) {
                $this->setPrimaryImage($next['id'], $image['property_id'], $tenantId);
            }
        }
        return true;
    }

    /**
     * Delete every image file of a property (rows go via ON DELETE CASCADE,
     * but are removed here too so this can run before the property delete).
     * @param int $propertyId Property ID
     * @param int $tenantId Tenant ID
     */
    public function deleteAllForProperty($propertyId, $tenantId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM {$this->table} WHERE property_id = :property_id AND tenant_id = :tenant_id"
        );
        $stmt->bindValue(':property_id', (int)$propertyId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll() as $image) {
            $path = self::absolutePath($image);
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
        $stmt = $this->conn->prepare(
            "DELETE FROM {$this->table} WHERE property_id = :property_id AND tenant_id = :tenant_id"
        );
        $stmt->bindValue(':property_id', (int)$propertyId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();

        $dir = realpath(UPLOAD_PATH . '/' . (int)$tenantId . '/properties/' . (int)$propertyId);
        if ($dir !== false && is_dir($dir)) {
            @rmdir($dir); // only succeeds when empty
        }
    }

    /**
     * Update image caption
     * @param int $imageId Image ID
     * @param string $caption Caption text
     * @param int $tenantId Tenant ID
     * @return bool Success status
     */
    public function updateCaption($imageId, $caption, $tenantId) {
        $caption = trim((string)$caption);
        return $this->update($imageId, $tenantId, array(
            'caption' => $caption === '' ? null : mb_substr($caption, 0, 255)
        ));
    }

    /**
     * Get image count for property
     * @param int $propertyId Property ID
     * @param int $tenantId Tenant ID
     * @return int Image count
     */
    public function getImageCount($propertyId, $tenantId) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE property_id = :property_id AND tenant_id = :tenant_id"
        );
        $stmt->bindValue(':property_id', (int)$propertyId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
}

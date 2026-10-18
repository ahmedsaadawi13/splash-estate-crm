<?php
// FILE: /app/controllers/PropertyGalleryController.php

/**
 * SplashEstate CRM - Property Gallery Controller
 * Manages property image galleries (JSON endpoints used by
 * views/partials/property_gallery.php) and serves the stored images.
 *
 * Images are stored outside the web root under
 * UPLOAD_PATH/{tenantId}/properties/{propertyId}/{random}.{ext}
 * and are only reachable through image($id), which checks tenant ownership.
 *
 * Routes (URL segment is case-insensitive: /propertygallery/...):
 *   GET  image/{id}            image bytes
 *   GET  getImages/{propertyId} JSON list
 *   POST upload/{propertyId}   multipart images[] (CSRF)
 *   POST setPrimary            image_id, property_id (CSRF)
 *   POST updateOrder           property_id, images[] (CSRF)
 *   POST deleteImage/{id}      (CSRF)
 *   POST updateCaption         image_id, caption (CSRF)
 */

class PropertyGalleryController extends Controller {

    const MAX_FILE_SIZE = 5242880; // 5 MB
    const MAX_FILES_PER_REQUEST = 10;
    const MAX_IMAGES_PER_PROPERTY = 50;
    const MAX_PIXELS = 50000000; // 50 MP

    /** Detected MIME type => stored extension */
    private static $allowedMimes = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    );

    /** Client-side extensions accepted */
    private static $allowedExtensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');

    private $imageModel;
    private $propertyModel;

    public function __construct() {
        $this->imageModel = $this->model('PropertyImage');
        $this->propertyModel = $this->model('Property');
    }

    /**
     * Serve an image after verifying it belongs to the current tenant
     * @param int $id Image ID
     */
    public function image($id) {
        $this->requirePermission('properties.view');

        $image = $this->imageModel->getById((int)$id, $this->getTenantId());
        $path = $image ? PropertyImage::absolutePath($image) : null;
        if (!$image || $path === null || !is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Image not found';
            exit;
        }

        $mime = isset($image['mime_type']) ? $image['mime_type'] : '';
        if (!isset(self::$allowedMimes[$mime])) {
            $mime = 'application/octet-stream';
        }

        $etag = '"' . md5($image['id'] . '|' . filemtime($path) . '|' . filesize($path)) . '"';
        header('Cache-Control: private, max-age=86400');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$image['file_name']);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . $downloadName . '"');
        readfile($path);
        exit;
    }

    /**
     * Upload property images (multipart field images[])
     * @param int $propertyId Property ID
     */
    public function upload($propertyId) {
        $this->requirePermission('properties.images');
        $this->requirePostWithCsrf('properties/view/' . (int)$propertyId);

        $tenantId = $this->getTenantId();
        $property = $this->propertyModel->getById((int)$propertyId, $tenantId);
        if (!$property) {
            $this->jsonResponse(array('success' => false, 'error' => 'Property not found'), 404);
        }

        if (empty($_FILES['images']) || !isset($_FILES['images']['name'])) {
            // post_max_size overflow empties $_FILES and $_POST
            $this->jsonResponse(array('success' => false, 'error' => 'No images uploaded (the request may exceed the server upload limit)'), 400);
        }

        $files = $this->reArrayFiles($_FILES['images']);
        if (count($files) > self::MAX_FILES_PER_REQUEST) {
            $this->jsonResponse(array('success' => false, 'error' => 'You can upload at most ' . self::MAX_FILES_PER_REQUEST . ' images at a time'), 400);
        }

        $existing = $this->imageModel->getImageCount($property['id'], $tenantId);
        if ($existing + count($files) > self::MAX_IMAGES_PER_PROPERTY) {
            $this->jsonResponse(array('success' => false, 'error' => 'A property can have at most ' . self::MAX_IMAGES_PER_PROPERTY . ' images'), 400);
        }

        $hasPrimary = (bool)$this->imageModel->getPrimaryImage($property['id'], $tenantId);
        $uploaded = array();
        $errors = array();

        foreach ($files as $file) {
            $result = $this->processImageUpload($file, $property, $tenantId, $existing, !$hasPrimary);
            if ($result['success']) {
                $uploaded[] = $result['image'];
                $existing++;
                $hasPrimary = true;
            } else {
                $errors[] = $result['error'];
            }
        }

        if (!empty($uploaded)) {
            logActivity($this->getUserId(), $tenantId, 'images_uploaded', 'property', $property['id']);
        }

        $this->jsonResponse(array(
            'success' => !empty($uploaded),
            'uploaded' => count($uploaded),
            'failed' => count($errors),
            'images' => $uploaded,
            'errors' => $errors,
            'error' => empty($uploaded) ? implode('; ', $errors) : null
        ), empty($uploaded) ? 422 : 200);
    }

    /**
     * Get property images as JSON
     * @param int $propertyId Property ID
     */
    public function getImages($propertyId) {
        $this->requirePermission('properties.view');

        $tenantId = $this->getTenantId();
        $property = $this->propertyModel->getById((int)$propertyId, $tenantId);
        if (!$property) {
            $this->jsonResponse(array('success' => false, 'error' => 'Property not found'), 404);
        }

        $images = array();
        foreach ($this->imageModel->getPropertyImages($property['id'], $tenantId) as $image) {
            $images[] = $this->presentImage($image);
        }

        $this->jsonResponse(array('success' => true, 'images' => $images));
    }

    /**
     * Set primary image
     */
    public function setPrimary() {
        $this->requirePermission('properties.images');
        $this->requirePostWithCsrf('properties/index');

        $tenantId = $this->getTenantId();
        $input = $this->input();
        $imageId = isset($input['image_id']) ? (int)$input['image_id'] : 0;
        $propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;

        if ($imageId <= 0 || $propertyId <= 0) {
            $this->jsonResponse(array('success' => false, 'error' => 'Missing parameters'), 400);
        }
        if (!$this->propertyModel->getById($propertyId, $tenantId)) {
            $this->jsonResponse(array('success' => false, 'error' => 'Property not found'), 404);
        }

        if ($this->imageModel->setPrimaryImage($imageId, $propertyId, $tenantId)) {
            $this->jsonResponse(array('success' => true, 'message' => 'Primary image updated'));
        }
        $this->jsonResponse(array('success' => false, 'error' => 'Image not found'), 404);
    }

    /**
     * Update image order: property_id + images (array of image ids in order)
     */
    public function updateOrder() {
        $this->requirePermission('properties.images');
        $this->requirePostWithCsrf('properties/index');

        $tenantId = $this->getTenantId();
        $input = $this->input();
        $propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;

        if ($propertyId <= 0 || empty($input['images']) || !is_array($input['images'])) {
            $this->jsonResponse(array('success' => false, 'error' => 'Missing parameters'), 400);
        }
        if (!$this->propertyModel->getById($propertyId, $tenantId)) {
            $this->jsonResponse(array('success' => false, 'error' => 'Property not found'), 404);
        }

        $order = 0;
        foreach (array_values($input['images']) as $imageId) {
            $this->imageModel->updateOrder((int)$imageId, $order++, $tenantId, $propertyId);
        }

        $this->jsonResponse(array('success' => true, 'message' => 'Image order updated'));
    }

    /**
     * Delete image
     * @param int $imageId Image ID
     */
    public function deleteImage($imageId) {
        $this->requirePermission('properties.images');
        $this->requirePostWithCsrf('properties/index');

        $tenantId = $this->getTenantId();
        $image = $this->imageModel->getById((int)$imageId, $tenantId);
        if (!$image) {
            $this->jsonResponse(array('success' => false, 'error' => 'Image not found'), 404);
        }

        if ($this->imageModel->deleteImage($image['id'], $tenantId)) {
            logActivity($this->getUserId(), $tenantId, 'image_deleted', 'property', $image['property_id']);
            $this->jsonResponse(array('success' => true, 'message' => 'Image deleted successfully'));
        }
        $this->jsonResponse(array('success' => false, 'error' => 'Failed to delete image'), 500);
    }

    /**
     * Update image caption
     */
    public function updateCaption() {
        $this->requirePermission('properties.images');
        $this->requirePostWithCsrf('properties/index');

        $tenantId = $this->getTenantId();
        $input = $this->input();

        if (empty($input['image_id']) || !isset($input['caption']) || !is_scalar($input['caption'])) {
            $this->jsonResponse(array('success' => false, 'error' => 'Missing parameters'), 400);
        }
        $image = $this->imageModel->getById((int)$input['image_id'], $tenantId);
        if (!$image) {
            $this->jsonResponse(array('success' => false, 'error' => 'Image not found'), 404);
        }

        if ($this->imageModel->updateCaption($image['id'], (string)$input['caption'], $tenantId)) {
            $this->jsonResponse(array('success' => true, 'message' => 'Caption updated'));
        }
        $this->jsonResponse(array('success' => false, 'error' => 'Failed to update caption'), 500);
    }

    /**
     * Request parameters from a JSON body or a regular form post
     * @return array
     */
    private function input() {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode((string)file_get_contents('php://input'), true);
            return is_array($decoded) ? $decoded : array();
        }
        return $_POST;
    }

    /**
     * Public representation of an image (never exposes the storage path)
     * @param array $image
     * @return array
     */
    private function presentImage($image) {
        return array(
            'id' => (int)$image['id'],
            'file_name' => (string)$image['file_name'],
            'caption' => isset($image['caption']) ? (string)$image['caption'] : '',
            'is_primary' => !empty($image['is_primary']),
            'display_order' => isset($image['display_order']) ? (int)$image['display_order'] : 0,
            'url' => BASE_URL . '/propertygallery/image/' . (int)$image['id']
        );
    }

    /**
     * Validate and store one uploaded image
     * @param array $file Normalised $_FILES entry
     * @param array $property Property row
     * @param int $tenantId Tenant ID
     * @param int $order display_order to assign
     * @param bool $makePrimary Whether this becomes the primary image
     * @return array array(success, image|error)
     */
    private function processImageUpload($file, $property, $tenantId, $order, $makePrimary) {
        $originalName = basename(str_replace('\\', '/', (string)$file['name']));
        $displayName = $originalName !== '' ? mb_substr($originalName, 0, 200) : 'image';

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $messages = array(
                UPLOAD_ERR_INI_SIZE => 'file is too large',
                UPLOAD_ERR_FORM_SIZE => 'file is too large',
                UPLOAD_ERR_PARTIAL => 'upload was interrupted',
                UPLOAD_ERR_NO_FILE => 'no file selected'
            );
            $reason = isset($messages[$file['error']]) ? $messages[$file['error']] : 'upload failed';
            return array('success' => false, 'error' => $displayName . ': ' . $reason);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return array('success' => false, 'error' => $displayName . ': invalid upload');
        }

        $size = (int)filesize($file['tmp_name']);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            return array('success' => false, 'error' => $displayName . ': must be smaller than 5 MB');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::$allowedExtensions, true)) {
            return array('success' => false, 'error' => $displayName . ': only JPG, PNG, GIF and WebP images are allowed');
        }

        // Real content type (never trust the browser-supplied $file['type'])
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $imageInfo = @getimagesize($file['tmp_name']);
        if (!isset(self::$allowedMimes[$mime]) || $imageInfo === false
            || !isset($imageInfo['mime']) || $imageInfo['mime'] !== $mime
            || (int)$imageInfo[0] <= 0 || (int)$imageInfo[1] <= 0) {
            return array('success' => false, 'error' => $displayName . ': not a valid image');
        }
        if ((int)$imageInfo[0] * (int)$imageInfo[1] > self::MAX_PIXELS) {
            return array('success' => false, 'error' => $displayName . ': image dimensions are too large');
        }

        $relativeDir = (int)$tenantId . '/properties/' . (int)$property['id'];
        $absoluteDir = UPLOAD_PATH . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
            error_log('Gallery upload: cannot create ' . $absoluteDir);
            return array('success' => false, 'error' => $displayName . ': storage is not writable');
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . self::$allowedMimes[$mime];
        $relativePath = $relativeDir . '/' . $storedName;
        $absolutePath = $absoluteDir . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            return array('success' => false, 'error' => $displayName . ': failed to store file');
        }
        @chmod($absolutePath, 0640);

        $imageId = $this->imageModel->addImage(array(
            'tenant_id' => $tenantId,
            'property_id' => $property['id'],
            'file_name' => $displayName,
            'file_path' => $relativePath,
            'file_size' => $size,
            'mime_type' => $mime,
            'is_primary' => $makePrimary ? 1 : 0,
            'display_order' => $order,
            'uploaded_by' => $this->getUserId()
        ));

        if (!$imageId) {
            @unlink($absolutePath);
            return array('success' => false, 'error' => $displayName . ': could not be saved');
        }

        return array('success' => true, 'image' => $this->presentImage(array(
            'id' => $imageId,
            'file_name' => $displayName,
            'caption' => '',
            'is_primary' => $makePrimary,
            'display_order' => $order
        )));
    }

    /**
     * Normalise $_FILES['images'] (single or multiple) into a list
     * @param array $filePost
     * @return array
     */
    private function reArrayFiles($filePost) {
        if (!is_array($filePost['name'])) {
            return array($filePost);
        }
        $files = array();
        foreach (array_keys($filePost['name']) as $i) {
            $files[] = array(
                'name' => $filePost['name'][$i],
                'type' => $filePost['type'][$i],
                'tmp_name' => $filePost['tmp_name'][$i],
                'error' => (int)$filePost['error'][$i],
                'size' => (int)$filePost['size'][$i]
            );
        }
        return $files;
    }
}

<?php
// FILE: /app/controllers/UploadController.php

/**
 * SplashEstate CRM - Upload Controller
 * Handles attachment upload, download and deletion (JSON API for the UI)
 */

class UploadController extends Controller {

    private $attachmentModel;

    /** related_to_type => permission needed to attach files to it */
    private static $relatedPermissions = array(
        'lead' => 'leads.edit',
        'client' => 'clients.edit',
        'property' => 'properties.edit',
        'deal' => 'deals.edit',
        'task' => 'tasks.edit'
    );

    public function __construct() {
        $this->attachmentModel = $this->model('Attachment');
    }

    /**
     * Upload a file (POST multipart: file, related_to_type, related_to_id, csrf_token)
     */
    public function upload() {
        $this->requireLoginJson();
        $this->requirePostWithCsrf('dashboard/index');

        $tenantId = $this->tenantOrFail();
        $userId = $this->getUserId();

        $relatedType = isset($_POST['related_to_type']) && is_string($_POST['related_to_type']) ? $_POST['related_to_type'] : '';
        $relatedId = isset($_POST['related_to_id']) ? (int)$_POST['related_to_id'] : 0;

        if (!isset(self::$relatedPermissions[$relatedType]) || $relatedId <= 0) {
            $this->jsonResponse(array('success' => false, 'message' => 'Related entity is required'), 400);
        }
        if (!can(self::$relatedPermissions[$relatedType])) {
            $this->jsonResponse(array('success' => false, 'message' => 'Permission denied'), 403);
        }
        if (!$this->attachmentModel->relatedExists($tenantId, $relatedType, $relatedId)) {
            $this->jsonResponse(array('success' => false, 'message' => 'Related record not found'), 404);
        }

        if (!isset($_FILES['file']) || !is_array($_FILES['file']) || is_array($_FILES['file']['name'])) {
            $this->jsonResponse(array('success' => false, 'message' => 'No file uploaded'), 400);
        }
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->jsonResponse(array('success' => false, 'message' => 'Upload failed (error code ' . (int)$file['error'] . ')'), 400);
        }

        $result = uploadFile($file, $tenantId);
        if (!$result['success']) {
            $this->jsonResponse(array('success' => false, 'message' => $result['message']), 400);
        }

        $originalName = $this->cleanFileName($file['name']);
        $attachmentId = $this->attachmentModel->createAttachment(array(
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'related_to_type' => $relatedType,
            'related_to_id' => $relatedId,
            'file_name' => $originalName,
            'file_path' => $result['path'],
            'file_size' => (int)$file['size'],
            'file_type' => strtolower(pathinfo($originalName, PATHINFO_EXTENSION))
        ));

        if ($attachmentId) {
            logActivity($userId, $tenantId, 'uploaded_file', $relatedType, $relatedId);
            $this->jsonResponse(array(
                'success' => true,
                'message' => 'File uploaded successfully',
                'attachment_id' => (int)$attachmentId
            ), 200);
        }

        deleteFile($result['path']);
        $this->jsonResponse(array('success' => false, 'message' => 'Failed to save attachment'), 500);
    }

    /**
     * Delete an attachment (POST + CSRF). Allowed for the uploader and tenant admins.
     * @param int $id Attachment ID
     */
    public function delete($id) {
        $this->requireLoginJson();
        $this->requirePostWithCsrf('dashboard/index');

        $tenantId = $this->tenantOrFail();
        $userId = (int)$this->getUserId();

        $attachment = $this->attachmentModel->getById((int)$id, $tenantId);
        if (!$attachment) {
            $this->jsonResponse(array('success' => false, 'message' => 'File not found'), 404);
        }
        $isOwner = (int)$attachment['user_id'] === $userId;
        if (!$isOwner && !$this->hasRole(array('tenant_admin', 'platform_admin'))) {
            $this->jsonResponse(array('success' => false, 'message' => 'Permission denied'), 403);
        }

        if ($this->attachmentModel->deleteAttachment((int)$attachment['id'], $tenantId)) {
            logActivity($userId, $tenantId, 'deleted_file', 'attachment', $attachment['id']);
            $this->jsonResponse(array('success' => true, 'message' => 'File deleted successfully'), 200);
        }
        $this->jsonResponse(array('success' => false, 'message' => 'Failed to delete file'), 500);
    }

    /**
     * Download an attachment of the current tenant
     * @param int $id Attachment ID
     */
    public function download($id) {
        $this->requireLogin();
        $tenantId = (int)$this->getTenantId();

        $attachment = $tenantId ? $this->attachmentModel->getById((int)$id, $tenantId) : false;
        if (!$attachment) {
            $this->notFound('File not found');
        }

        $base = realpath(UPLOAD_PATH);
        $filePath = realpath(UPLOAD_PATH . '/' . $attachment['file_path']);
        if ($base === false || $filePath === false || strpos($filePath, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
            $this->notFound('File not found on server');
        }

        $downloadName = $this->cleanFileName($attachment['file_name']);
        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);

        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, no-store');

        readfile($filePath);
        exit;
    }

    /**
     * JSON-friendly login check (this controller is called via AJAX)
     */
    private function requireLoginJson() {
        if (!$this->isLoggedIn()) {
            $this->jsonResponse(array('success' => false, 'message' => 'Authentication required'), 401);
        }
    }

    /**
     * Tenant of the current user, or a JSON 403
     * @return int
     */
    private function tenantOrFail() {
        $tenantId = $this->getTenantId();
        if (empty($tenantId)) {
            $this->jsonResponse(array('success' => false, 'message' => 'No agency account'), 403);
        }
        return (int)$tenantId;
    }

    /**
     * Strip path components and control characters from a user-supplied file name
     * @param string $name
     * @return string
     */
    private function cleanFileName($name) {
        $name = basename(str_replace('\\', '/', (string)$name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name);
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }
        return substr($name, 0, 255);
    }
}

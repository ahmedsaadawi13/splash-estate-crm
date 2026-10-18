<?php
// FILE: /app/controllers/BulkOperationsController.php

/**
 * SplashEstate CRM - Bulk Operations Controller
 * JSON endpoints used by the bulk-actions toolbar (views/partials/bulk_operations.php).
 *
 * Request body: JSON (or form fields) with entity_type, ids[] and the action's
 * parameters. Every endpoint requires POST + CSRF token (X-CSRF-Token header or
 * csrf_token field) and the matching RBAC permission.
 */

class BulkOperationsController extends Controller {

    private $bulkService;

    public function __construct() {
        require_once APP_PATH . '/helpers/BulkOperationService.php';
        $this->bulkService = new BulkOperationService();
    }

    /**
     * Bulk delete records ({module}.delete)
     */
    public function delete() {
        list($entityType, $ids) = $this->begin('delete');
        $this->respond($this->bulkService->bulkDelete($entityType, $ids, $this->getTenantId(), $this->getUserId()));
    }

    /**
     * Bulk status / deal stage change ({module}.edit)
     */
    public function updateStatus() {
        list($entityType, $ids, $input) = $this->begin('edit');
        $status = isset($input['status']) && is_string($input['status']) ? trim($input['status']) : '';
        if ($status === '') {
            $this->fail('Please choose a status', 422);
        }
        $this->respond($this->bulkService->bulkUpdateStatus($entityType, $ids, $status, $this->getTenantId(), $this->getUserId()));
    }

    /**
     * Bulk assign to an agent of the same tenant ({module}.edit)
     */
    public function assign() {
        list($entityType, $ids, $input) = $this->begin('edit');
        $assigneeId = isset($input['user_id']) && is_scalar($input['user_id']) ? (int)$input['user_id'] : 0;
        if ($assigneeId <= 0 || !$this->bulkService->findTenantUser($assigneeId, $this->getTenantId())) {
            $this->fail('Please choose a valid, active user from your organization', 422);
        }
        $this->respond($this->bulkService->bulkAssign($entityType, $ids, $assigneeId, $this->getTenantId(), $this->getUserId()));
    }

    /**
     * Bulk email leads/clients ({module}.edit). Capped per request and rate-limited per hour.
     */
    public function email() {
        list($entityType, $ids, $input) = $this->begin('edit');
        $subject = isset($input['subject']) && is_string($input['subject']) ? trim($input['subject']) : '';
        $message = isset($input['message']) && is_string($input['message']) ? trim($input['message']) : '';
        if ($subject === '' || $message === '') {
            $this->fail('Subject and message are required', 422);
        }
        if (strlen($subject) > 200 || strlen($message) > 20000) {
            $this->fail('Subject or message is too long', 422);
        }
        $this->respond($this->bulkService->bulkEmail($entityType, $ids, $subject, $message, $this->getTenantId(), $this->getUserId()));
    }

    /**
     * Bulk convert leads to clients (leads.edit + clients.create)
     */
    public function convertLeads() {
        $input = $this->input();
        $input['entity_type'] = 'leads';
        list(, $ids) = $this->begin('edit', $input);
        if (!can('clients.create')) {
            $this->fail('Permission denied', 403);
        }
        $this->respond($this->bulkService->bulkConvertLeads($ids, $this->getTenantId(), $this->getUserId()));
    }

    /**
     * Export the selected records as CSV (regular form POST, not AJAX).
     * leads -> leads.export; other modules -> {module}.view + reports.export
     */
    public function export() {
        $this->requireLogin();
        $this->requirePostWithCsrf('dashboard/index');

        $entityType = isset($_POST['entity_type']) && is_string($_POST['entity_type']) ? $_POST['entity_type'] : '';
        $cfg = BulkOperationService::entityConfig($entityType);
        if (!$cfg) {
            $this->setFlash('error', 'Invalid record type');
            $this->redirect('dashboard/index');
        }
        if ($entityType === 'leads') {
            $this->requirePermission('leads.export');
        } else {
            $this->requirePermission($cfg['perm'] . '.view');
            $this->requirePermission('reports.export');
        }

        $ids = BulkOperationService::normalizeIds(isset($_POST['ids']) ? $_POST['ids'] : array());
        if (empty($ids)) {
            $this->setFlash('error', $ids === false ? 'Too many records selected' : 'No records selected');
            $this->redirect($entityType . '/index');
        }

        $tenantId = (int)$this->getTenantId();
        require_once APP_PATH . '/helpers/ExportService.php';
        $exportService = new ExportService();
        $rows = $exportService->fetchRows($entityType, $tenantId, array(), $ids);
        logActivity($this->getUserId(), $tenantId, 'exported_csv', $cfg['singular'], 0);

        $exportService->exportToCSV(
            $rows,
            ExportService::columns($entityType),
            $entityType . '_selected_' . date('Y-m-d_His') . '.csv'
        );
    }

    /**
     * Common checks for the JSON endpoints: login, POST + CSRF, tenant,
     * entity type, permission and id list.
     * @param string $action Permission action (delete|edit)
     * @param array|null $input Pre-parsed input
     * @return array array($entityType, $ids, $input)
     */
    private function begin($action, $input = null) {
        if (!$this->isLoggedIn()) {
            $this->fail('Authentication required', 401);
        }
        $this->requirePostWithCsrf('dashboard/index');
        if (!$this->getTenantId()) {
            $this->fail('Bulk operations are only available inside a tenant account', 403);
        }

        if ($input === null) {
            $input = $this->input();
        }
        $entityType = isset($input['entity_type']) && is_string($input['entity_type']) ? $input['entity_type'] : '';
        $cfg = BulkOperationService::entityConfig($entityType);
        if (!$cfg) {
            $this->fail('Invalid record type', 400);
        }
        if (!can($cfg['perm'] . '.' . $action)) {
            $this->fail('Permission denied', 403);
        }

        $ids = BulkOperationService::normalizeIds(isset($input['ids']) ? $input['ids'] : array());
        if ($ids === false) {
            $this->fail('You can process at most ' . BulkOperationService::MAX_IDS . ' records at a time', 422);
        }
        if (empty($ids)) {
            $this->fail('No records selected', 422);
        }

        return array($entityType, $ids, $input);
    }

    /**
     * Request input from a JSON body or form fields
     * @return array
     */
    private function input() {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode(file_get_contents('php://input'), true);
            return is_array($decoded) ? $decoded : array();
        }
        return $_POST;
    }

    /**
     * @param array $result Service result
     */
    private function respond($result) {
        if (empty($result['success'])) {
            $code = !empty($result['rate_limited']) ? 429 : 422;
            $this->jsonResponse(array('success' => false, 'error' => $result['error'], 'message' => $result['error']), $code);
        }
        $this->jsonResponse($result);
    }

    /**
     * @param string $message
     * @param int $code
     */
    private function fail($message, $code) {
        $this->jsonResponse(array('success' => false, 'error' => $message, 'message' => $message), $code);
    }
}

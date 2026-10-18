<?php
// FILE: /app/controllers/WebhooksController.php

/**
 * SplashEstate CRM - Webhooks Controller
 * Manages outgoing webhook endpoints and their delivery logs.
 * Every action requires the webhooks.manage permission and is tenant scoped.
 */

class WebhooksController extends Controller {

    private $webhookModel;
    private $logModel;
    private $webhookService;

    public function __construct() {
        $this->webhookModel = $this->model('Webhook');
        $this->logModel = $this->model('WebhookLog');

        require_once APP_PATH . '/helpers/WebhookService.php';
        $this->webhookService = new WebhookService();
    }

    /**
     * List webhooks
     */
    public function index() {
        $tenantId = $this->requireWebhookAccess();

        $this->view('webhooks/index', array(
            'title' => 'Webhooks',
            'webhooks' => $this->webhookModel->getAllWithStats($tenantId),
            'availableEvents' => $this->webhookModel->getAvailableEvents()
        ));
    }

    /**
     * Create webhook (GET form / POST save)
     */
    public function create() {
        $tenantId = $this->requireWebhookAccess();

        $formData = array('name' => '', 'url' => '', 'events' => '', 'status' => 'active', 'retry_enabled' => 1, 'max_retries' => 3);
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('webhooks/create');

            list($formData, $errors) = $this->readForm();
            $secret = $this->webhookModel->generateSecret();

            if (empty($errors)) {
                $formData['tenant_id'] = $tenantId;
                $formData['created_by'] = $this->getUserId();
                $formData['secret'] = $secret;

                $webhookId = $this->webhookModel->create($formData);
                if ($webhookId) {
                    logActivity($this->getUserId(), $tenantId, 'created', 'webhook', $webhookId);
                    $this->revealSecretOnce($webhookId, $secret);
                    $this->setFlash('success', 'Webhook created. Copy the signing secret now - it will not be shown again.');
                    $this->redirect('webhooks/view/' . $webhookId);
                }
                $errors['general'] = 'Failed to create webhook. Please try again.';
            }
        }

        $this->view('webhooks/create', array(
            'title' => 'Create Webhook',
            'availableEvents' => $this->webhookModel->getAvailableEvents(),
            'errors' => $errors,
            'formData' => $formData
        ));
    }

    /**
     * Webhook details with recent deliveries (URL: /webhooks/view/{id})
     * @param int $id Webhook ID
     */
    public function show($id) {
        $tenantId = $this->requireWebhookAccess();
        $webhook = $this->findWebhookOrRedirect($id, $tenantId);

        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $perPage = 20;
        $total = $this->logModel->countByWebhook($webhook['id']);

        // A freshly created/regenerated secret is shown exactly once
        $revealedSecret = null;
        if (isset($_SESSION['webhook_secret_once'][$webhook['id']])) {
            $revealedSecret = $_SESSION['webhook_secret_once'][$webhook['id']];
            unset($_SESSION['webhook_secret_once'][$webhook['id']]);
        }

        $this->view('webhooks/view', array(
            'title' => 'Webhook: ' . $webhook['name'],
            'webhook' => $webhook,
            'events' => Webhook::parseEvents($webhook['events']),
            'availableEvents' => $this->webhookModel->getAvailableEvents(),
            'maskedSecret' => Webhook::maskSecret($webhook['secret']),
            'revealedSecret' => $revealedSecret,
            'logs' => $this->logModel->getByWebhook($webhook['id'], $perPage, ($page - 1) * $perPage),
            'statusCounts' => $this->logModel->countByStatus($webhook['id']),
            'currentPage' => $page,
            'totalPages' => (int)ceil($total / $perPage)
        ));
    }

    /**
     * Edit webhook (GET form / POST save)
     * @param int $id Webhook ID
     */
    public function edit($id) {
        $tenantId = $this->requireWebhookAccess();
        $webhook = $this->findWebhookOrRedirect($id, $tenantId);

        $formData = $webhook;
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('webhooks/edit/' . (int)$webhook['id']);

            list($formData, $errors) = $this->readForm();
            if (empty($errors)) {
                if ($this->webhookModel->update($webhook['id'], $tenantId, $formData)) {
                    logActivity($this->getUserId(), $tenantId, 'updated', 'webhook', $webhook['id']);
                    $this->setFlash('success', 'Webhook updated successfully');
                    $this->redirect('webhooks/view/' . (int)$webhook['id']);
                }
                $errors['general'] = 'Failed to update webhook. Please try again.';
            }
        }

        $this->view('webhooks/edit', array(
            'title' => 'Edit Webhook',
            'webhook' => $webhook,
            'formData' => $formData,
            'availableEvents' => $this->webhookModel->getAvailableEvents(),
            'errors' => $errors
        ));
    }

    /**
     * Delete webhook (POST + CSRF)
     * @param int $id Webhook ID
     */
    public function delete($id) {
        $tenantId = $this->requireWebhookAccess();
        $this->requirePostWithCsrf('webhooks/index');
        $webhook = $this->findWebhookOrRedirect($id, $tenantId);

        if ($this->webhookModel->delete($webhook['id'], $tenantId)) {
            logActivity($this->getUserId(), $tenantId, 'deleted', 'webhook', $webhook['id']);
            $this->setFlash('success', 'Webhook deleted');
        } else {
            $this->setFlash('error', 'Failed to delete webhook');
        }

        $this->redirect('webhooks/index');
    }

    /**
     * Enable/disable webhook (POST + CSRF)
     * @param int $id Webhook ID
     */
    public function toggle($id) {
        $tenantId = $this->requireWebhookAccess();
        $this->requirePostWithCsrf('webhooks/index');
        $webhook = $this->findWebhookOrRedirect($id, $tenantId);

        $newStatus = $webhook['status'] === 'active' ? 'inactive' : 'active';
        if ($this->webhookModel->update($webhook['id'], $tenantId, array('status' => $newStatus))) {
            logActivity($this->getUserId(), $tenantId, $newStatus === 'active' ? 'enabled' : 'disabled', 'webhook', $webhook['id']);
            $this->setFlash('success', 'Webhook ' . ($newStatus === 'active' ? 'enabled' : 'disabled'));
        } else {
            $this->setFlash('error', 'Failed to update webhook');
        }

        $this->redirect($this->safeReturnPath('webhooks/view/' . (int)$webhook['id']));
    }

    /**
     * Send a test event to the endpoint (POST + CSRF)
     * @param int $id Webhook ID
     */
    public function test($id) {
        $tenantId = $this->requireWebhookAccess();
        $this->requirePostWithCsrf('webhooks/view/' . (int)$id);
        $webhook = $this->findWebhookOrRedirect($id, $tenantId);

        $result = $this->webhookService->sendTest($webhook);
        logActivity($this->getUserId(), $tenantId, 'tested', 'webhook', $webhook['id']);

        if ($this->wantsJson()) {
            $this->jsonResponse($result, 200);
        }

        if ($result['success']) {
            $this->setFlash('success', 'Test event delivered (HTTP ' . $result['status_code'] . ', ' . $result['duration_ms'] . ' ms).');
        } else {
            $this->setFlash('error', 'Test event failed: ' . ($result['error'] !== '' ? $result['error'] : 'HTTP ' . $result['status_code']));
        }
        $this->redirect('webhooks/view/' . (int)$webhook['id']);
    }

    /**
     * Delivery log for all webhooks of the tenant
     */
    public function logs() {
        $tenantId = $this->requireWebhookAccess();

        $filters = array(
            'status' => isset($_GET['status']) ? (string)$_GET['status'] : '',
            'webhook_id' => isset($_GET['webhook_id']) ? (int)$_GET['webhook_id'] : 0
        );
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $perPage = 50;
        $total = $this->logModel->countByTenant($tenantId, $filters);

        $this->view('webhooks/logs', array(
            'title' => 'Webhook Delivery Log',
            'logs' => $this->logModel->getByTenant($tenantId, $perPage, ($page - 1) * $perPage, $filters),
            'webhooks' => $this->webhookModel->getAllWithStats($tenantId),
            'filters' => $filters,
            'currentPage' => $page,
            'totalPages' => (int)ceil($total / $perPage),
            'total' => $total
        ));
    }

    /**
     * Generate a new signing secret (POST + CSRF); shown once on the details page
     * @param int $id Webhook ID
     */
    public function regenerateSecret($id) {
        $tenantId = $this->requireWebhookAccess();
        $this->requirePostWithCsrf('webhooks/view/' . (int)$id);
        $webhook = $this->findWebhookOrRedirect($id, $tenantId);

        $newSecret = $this->webhookModel->generateSecret();
        if ($this->webhookModel->update($webhook['id'], $tenantId, array('secret' => $newSecret))) {
            logActivity($this->getUserId(), $tenantId, 'regenerated_secret', 'webhook', $webhook['id']);
            if ($this->wantsJson()) {
                $this->jsonResponse(array('success' => true, 'secret' => $newSecret));
            }
            $this->revealSecretOnce($webhook['id'], $newSecret);
            $this->setFlash('success', 'New signing secret generated. Update your receiver - the old secret no longer works.');
        } else {
            $this->setFlash('error', 'Failed to regenerate secret');
        }
        $this->redirect('webhooks/view/' . (int)$webhook['id']);
    }

    /**
     * Permission + tenant gate shared by every action
     * @return int Tenant ID
     */
    private function requireWebhookAccess() {
        $this->requirePermission('webhooks.manage');
        $tenantId = $this->getTenantId();
        if (empty($tenantId)) {
            $this->setFlash('error', 'Webhooks are configured per agency. Sign in with an agency account.');
            $this->redirect('dashboard/index');
        }
        return (int)$tenantId;
    }

    /**
     * Load a webhook of the current tenant or redirect with an error
     */
    private function findWebhookOrRedirect($id, $tenantId) {
        $webhook = ctype_digit((string)$id) ? $this->webhookModel->getById((int)$id, $tenantId) : false;
        if (!$webhook) {
            if ($this->wantsJson()) {
                $this->jsonResponse(array('success' => false, 'message' => 'Webhook not found'), 404);
            }
            $this->setFlash('error', 'Webhook not found');
            $this->redirect('webhooks/index');
        }
        return $webhook;
    }

    /**
     * Read and validate the create/edit form
     * @return array array($formData, $errors)
     */
    private function readForm() {
        $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        $url = isset($_POST['url']) ? trim((string)$_POST['url']) : '';
        $events = $this->webhookModel->normalizeEvents(isset($_POST['events']) ? $_POST['events'] : array());
        $status = isset($_POST['status']) && $_POST['status'] === 'inactive' ? 'inactive' : 'active';
        $maxRetries = isset($_POST['max_retries']) ? (int)$_POST['max_retries'] : 3;

        $formData = array(
            'name' => $name,
            'url' => $url,
            'events' => $events,
            'status' => $status,
            'retry_enabled' => !empty($_POST['retry_enabled']) ? 1 : 0,
            'max_retries' => max(1, min(10, $maxRetries))
        );

        $errors = array();
        if ($name === '') {
            $errors['name'] = 'Name is required';
        } elseif (strlen($name) > 255) {
            $errors['name'] = 'Name must be at most 255 characters';
        }
        $urlError = null;
        if ($url === '') {
            $errors['url'] = 'Endpoint URL is required';
        } elseif ($this->webhookService->validateUrl($url, $urlError) === false) {
            $errors['url'] = $urlError;
        }
        if ($events === '') {
            $errors['events'] = 'Select at least one event';
        }

        return array($formData, $errors);
    }

    /**
     * Keep a secret in the session so the next details page shows it once
     */
    private function revealSecretOnce($webhookId, $secret) {
        if (!isset($_SESSION['webhook_secret_once']) || !is_array($_SESSION['webhook_secret_once'])) {
            $_SESSION['webhook_secret_once'] = array();
        }
        $_SESSION['webhook_secret_once'][(int)$webhookId] = $secret;
    }

    /**
     * Only allow redirects back to webhook pages
     */
    private function safeReturnPath($default) {
        $return = isset($_POST['return_to']) ? (string)$_POST['return_to'] : '';
        if (preg_match('#^webhooks/(index|logs|view/\d+)$#', $return)) {
            return $return;
        }
        return $default;
    }
}

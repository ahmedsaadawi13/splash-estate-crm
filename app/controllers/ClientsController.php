<?php
// FILE: /app/controllers/ClientsController.php

/**
 * SplashEstate CRM - Clients Controller
 * Manages client operations
 */

class ClientsController extends Controller {

    private $clientModel;

    /** Editable client fields => max length */
    private static $fields = array(
        'first_name' => 100, 'last_name' => 100, 'email' => 255, 'phone' => 50, 'phone_secondary' => 50,
        'address' => 1000, 'city' => 100, 'state' => 100, 'zip' => 20, 'client_type' => 20, 'notes' => 10000
    );

    public function __construct() {
        $this->clientModel = $this->model('Client');
    }

    /**
     * List clients
     */
    public function index() {
        $this->requirePermission('clients.view');
        $tenantId = $this->requireTenant();

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = 20;

        $filters = array();
        foreach (array('client_type', 'status', 'search') as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key]) && trim($_GET[$key]) !== '') {
                $filters[$key] = trim($_GET[$key]);
            }
        }
        if (isset($filters['client_type']) && !in_array($filters['client_type'], Client::TYPES, true)) {
            unset($filters['client_type']);
        }
        if (isset($filters['status']) && !in_array($filters['status'], Client::STATUSES, true)) {
            unset($filters['status']);
        }

        $clients = $this->clientModel->getClientsWithInfo($tenantId, $page, $perPage, $filters);
        $totalClients = $this->clientModel->count($tenantId, $filters);

        $this->view('clients/index', array(
            'title' => 'Clients',
            'clients' => $clients,
            'currentPage' => $page,
            'totalPages' => (int)ceil($totalClients / $perPage),
            'totalClients' => $totalClients,
            'filters' => $filters
        ));
    }

    /**
     * Client detail (URL: /clients/view/{id})
     * @param int $id Client ID
     */
    public function show($id) {
        $this->requirePermission('clients.view');
        $tenantId = $this->requireTenant();

        $client = $this->clientModel->getClientWithInfo((int)$id, $tenantId);
        if (!$client) {
            $this->setFlash('error', 'Client not found');
            $this->redirect('clients/index');
        }
        $clientId = (int)$client['id'];

        $this->view('clients/view', array(
            'title' => 'Client: ' . trim($client['first_name'] . ' ' . (string)$client['last_name']),
            'client' => $client,
            'activities' => $this->model('Activity')->getByRelated($tenantId, 'client', $clientId),
            'attachments' => $this->model('Attachment')->getByRelated($tenantId, 'client', $clientId),
            'tasks' => $this->model('Task')->getByRelated($tenantId, 'client', $clientId),
            'deals' => $this->clientModel->getDeals($clientId, $tenantId),
            'properties' => $this->clientModel->getOwnedProperties($clientId, $tenantId)
        ));
    }

    /**
     * Create client (GET form, POST save)
     */
    public function create() {
        $this->requirePermission('clients.create');
        $tenantId = $this->requireTenant();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processCreate($tenantId);
            return;
        }

        $this->view('clients/create', array(
            'title' => 'Create Client',
            'errors' => array(),
            'formData' => array('client_type' => 'buyer', 'status' => 'active')
        ));
    }

    /**
     * Save a new client
     * @param int $tenantId Tenant ID
     */
    private function processCreate($tenantId) {
        $this->requirePostWithCsrf('clients/create');

        $formData = $this->collectForm(false);
        $errors = $this->validateForm($formData, false);

        if (!empty($errors)) {
            $this->view('clients/create', array('title' => 'Create Client', 'errors' => $errors, 'formData' => $formData));
            return;
        }

        $formData['tenant_id'] = $tenantId;
        $formData['status'] = 'active';

        $clientId = $this->clientModel->createClient($formData);

        if ($clientId) {
            logActivity($this->getUserId(), $tenantId, 'created', 'client', $clientId);
            dispatchEvent($tenantId, 'client.created', $this->clientModel->getById($clientId, $tenantId));
            $this->setFlash('success', 'Client created successfully');
            $this->redirect('clients/view/' . $clientId);
        }

        $this->setFlash('error', 'Failed to create client');
        $this->redirect('clients/create');
    }

    /**
     * Edit client (GET form, POST save)
     * @param int $id Client ID
     */
    public function edit($id) {
        $this->requirePermission('clients.edit');
        $tenantId = $this->requireTenant();

        $client = $this->clientModel->getById((int)$id, $tenantId);
        if (!$client) {
            $this->setFlash('error', 'Client not found');
            $this->redirect('clients/index');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processEdit($client, $tenantId);
            return;
        }

        $this->view('clients/edit', array('title' => 'Edit Client', 'client' => $client, 'formData' => $client, 'errors' => array()));
    }

    /**
     * Save client changes
     * @param array $client Current client row
     * @param int $tenantId Tenant ID
     */
    private function processEdit($client, $tenantId) {
        $id = (int)$client['id'];
        $this->requirePostWithCsrf('clients/edit/' . $id);

        $formData = $this->collectForm(true);
        $errors = $this->validateForm($formData, true);

        if (!empty($errors)) {
            $this->view('clients/edit', array(
                'title' => 'Edit Client',
                'client' => $client,
                'formData' => array_merge($client, $formData),
                'errors' => $errors
            ));
            return;
        }

        $update = array();
        foreach ($formData as $key => $value) {
            $update[$key] = ($value === '' && $key !== 'first_name') ? null : $value;
        }

        if ($this->clientModel->update($id, $tenantId, $update)) {
            logActivity($this->getUserId(), $tenantId, 'updated', 'client', $id);
            $updated = $this->clientModel->getById($id, $tenantId);
            $updated['old_status'] = $client['status'];
            dispatchEvent($tenantId, 'client.updated', $updated);
            $this->setFlash('success', 'Client updated successfully');
            $this->redirect('clients/view/' . $id);
        }

        $this->setFlash('error', 'Failed to update client');
        $this->redirect('clients/edit/' . $id);
    }

    /**
     * Delete client (POST + CSRF)
     * @param int $id Client ID
     */
    public function delete($id) {
        $this->requirePermission('clients.delete');
        $tenantId = $this->requireTenant();
        $this->requirePostWithCsrf('clients/index');

        $client = $this->clientModel->getById((int)$id, $tenantId);
        if (!$client) {
            $this->setFlash('error', 'Client not found');
            $this->redirect('clients/index');
        }

        if ($this->clientModel->delete((int)$client['id'], $tenantId)) {
            logActivity($this->getUserId(), $tenantId, 'deleted', 'client', $client['id']);
            dispatchEvent($tenantId, 'client.deleted', $client);
            $this->setFlash('success', 'Client deleted successfully');
        } else {
            $this->setFlash('error', 'Failed to delete client');
        }

        $this->redirect('clients/index');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Current tenant ID, or redirect when the user has no tenant (platform admin)
     * @return int
     */
    private function requireTenant() {
        $tenantId = $this->getTenantId();
        if (empty($tenantId)) {
            $this->setFlash('error', 'Clients belong to an agency account. Log in as an agency user to manage clients.');
            $this->redirect('dashboard/index');
        }
        return (int)$tenantId;
    }

    /**
     * Collect trimmed raw form values
     * @param bool $withStatus Include status (edit form)
     * @return array
     */
    private function collectForm($withStatus) {
        $data = array();
        foreach (array_keys(self::$fields) as $field) {
            $data[$field] = isset($_POST[$field]) && is_string($_POST[$field]) ? trim($_POST[$field]) : '';
        }
        if ($withStatus) {
            $data['status'] = isset($_POST['status']) && is_string($_POST['status']) ? trim($_POST['status']) : '';
        }
        return $data;
    }

    /**
     * Validate client form data
     * @param array $data
     * @param bool $withStatus
     * @return array field => message
     */
    private function validateForm($data, $withStatus) {
        $errors = validateRequired($data, array('first_name', 'client_type'));
        foreach (self::$fields as $field => $max) {
            if (strlen($data[$field]) > $max) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be at most {$max} characters";
            }
        }
        if ($data['email'] !== '' && !$this->validateEmail($data['email'])) {
            $errors['email'] = 'Invalid email format';
        }
        if ($data['client_type'] !== '' && !in_array($data['client_type'], Client::TYPES, true)) {
            $errors['client_type'] = 'Invalid client type';
        }
        if ($withStatus && !in_array($data['status'], Client::STATUSES, true)) {
            $errors['status'] = 'Invalid status';
        }
        return $errors;
    }
}

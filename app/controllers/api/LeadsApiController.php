<?php
// FILE: /app/controllers/api/LeadsApiController.php

/**
 * SplashEstate CRM - Leads API Controller
 * REST API for managing leads.
 *
 * Authentication: tenant API key in the "X-API-Key" header
 * (or "Authorization: Bearer <key>"). Keys in the query string are rejected.
 *
 * Endpoints:
 *   GET  /api/leads                 list (page, per_page<=100, status, source, search, updated_since)
 *   GET  /api/leads/index           same as above
 *   POST /api/leads                 create (JSON body)
 *   POST /api/leads/create          create (JSON body)
 *   GET  /api/leads/show/{id}       one lead
 *
 * Errors are JSON: {"success": false, "error": "...", "errors": {...}}
 */

class LeadsApiController {

    /** Fields exposed by the API */
    private static $publicFields = array(
        'id', 'first_name', 'last_name', 'email', 'phone', 'source', 'status', 'interest_type',
        'budget_min', 'budget_max', 'notes', 'tags', 'assigned_to', 'score', 'grade', 'created_at', 'updated_at'
    );

    private $leadModel;

    public function __construct() {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        require_once APP_PATH . '/models/Lead.php';
        require_once APP_PATH . '/models/User.php';
        $this->leadModel = new Lead();
    }

    /**
     * GET: list leads. POST: create a lead (REST-style POST /api/leads).
     */
    public function index() {
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'POST') {
            $this->create();
            return;
        }
        if ($method !== 'GET') {
            $this->error('Method not allowed', 405, array('Allow: GET, POST'));
        }

        $tenant = $this->authenticate();

        $page = $this->intParam('page', 1, 1, 100000);
        $perPage = $this->intParam('per_page', 20, 1, 100);

        $filters = array();
        if (isset($_GET['status']) && is_string($_GET['status']) && $_GET['status'] !== '') {
            if (!in_array($_GET['status'], Lead::STATUSES, true)) {
                $this->error('Validation failed', 422, array(), array('status' => 'Must be one of: ' . implode(', ', Lead::STATUSES)));
            }
            $filters['status'] = $_GET['status'];
        }
        foreach (array('source', 'search', 'tag') as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key]) && trim($_GET[$key]) !== '') {
                $filters[$key] = substr(trim($_GET[$key]), 0, 100);
            }
        }
        if (isset($_GET['grade']) && in_array($_GET['grade'], array('A', 'B', 'C', 'D', 'F'), true)) {
            $filters['grade'] = $_GET['grade'];
        }

        $tenantId = (int)$tenant['id'];
        $leads = $this->leadModel->getLeadsWithUser($tenantId, $page, $perPage, $filters);
        $total = $this->leadModel->countLeads($tenantId, $filters);

        $this->respond(array(
            'success' => true,
            'data' => array(
                'leads' => array_map(array($this, 'present'), $leads),
                'pagination' => array(
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $perPage)
                )
            )
        ), 200);
    }

    /**
     * GET one lead: /api/leads/show/{id}
     * @param int $id Lead ID
     */
    public function show($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->error('Method not allowed', 405, array('Allow: GET'));
        }
        $tenant = $this->authenticate();

        if (!ctype_digit((string)$id)) {
            $this->error('Lead not found', 404);
        }
        $lead = $this->leadModel->getLeadWithUser((int)$id, (int)$tenant['id']);
        if (!$lead) {
            $this->error('Lead not found', 404);
        }

        $this->respond(array('success' => true, 'data' => array('lead' => $this->present($lead))), 200);
    }

    /**
     * Create a new lead (JSON body). Status is always "new".
     */
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->error('Method not allowed', 405, array('Allow: POST'));
        }

        $tenant = $this->authenticate();
        $tenantId = (int)$tenant['id'];

        $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';
        if (strpos($contentType, 'application/json') === false) {
            $this->error('Content-Type must be application/json', 415);
        }

        $raw = file_get_contents('php://input');
        if (strlen($raw) > 65536) {
            $this->error('Request body too large', 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || array_keys($data) === range(0, count($data) - 1) && !empty($data)) {
            $this->error('Invalid JSON input: expected an object', 400);
        }

        $errors = array();
        $lead = array();

        // Strings
        $limits = array('first_name' => 100, 'last_name' => 100, 'email' => 255, 'phone' => 50, 'source' => 100, 'notes' => 10000);
        foreach ($limits as $field => $max) {
            if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                $lead[$field] = null;
                continue;
            }
            if (!is_scalar($data[$field])) {
                $errors[$field] = 'Must be a string';
                continue;
            }
            $value = trim((string)$data[$field]);
            if (strlen($value) > $max) {
                $errors[$field] = "Must be at most {$max} characters";
            }
            $lead[$field] = $value === '' ? null : $value;
        }
        if (empty($lead['first_name']) && !isset($errors['first_name'])) {
            $errors['first_name'] = 'First name is required';
        }
        if (!empty($lead['email']) && !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        // Enum
        $lead['interest_type'] = null;
        if (isset($data['interest_type']) && $data['interest_type'] !== null && $data['interest_type'] !== '') {
            if (!is_string($data['interest_type']) || !in_array($data['interest_type'], Lead::INTEREST_TYPES, true)) {
                $errors['interest_type'] = 'Must be one of: ' . implode(', ', Lead::INTEREST_TYPES);
            } else {
                $lead['interest_type'] = $data['interest_type'];
            }
        }

        // Budgets
        foreach (array('budget_min', 'budget_max') as $field) {
            $lead[$field] = null;
            if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') {
                if (!is_numeric($data[$field]) || (float)$data[$field] < 0 || (float)$data[$field] > 9999999999999) {
                    $errors[$field] = 'Must be a positive number';
                } else {
                    $lead[$field] = (string)round((float)$data[$field], 2);
                }
            }
        }
        if ($lead['budget_min'] !== null && $lead['budget_max'] !== null && (float)$lead['budget_min'] > (float)$lead['budget_max']) {
            $errors['budget_max'] = 'Must be greater than or equal to budget_min';
        }

        // Tags: array or comma-separated string
        $lead['tags'] = null;
        if (isset($data['tags']) && $data['tags'] !== null && $data['tags'] !== '') {
            if (is_array($data['tags'])) {
                $lead['tags'] = Lead::normalizeTags(array_filter($data['tags'], 'is_scalar'));
            } elseif (is_string($data['tags'])) {
                $lead['tags'] = Lead::normalizeTags($data['tags']);
            } else {
                $errors['tags'] = 'Must be an array of strings or a comma-separated string';
            }
        }

        // Assignee must be an active user of this tenant
        $lead['assigned_to'] = null;
        if (isset($data['assigned_to']) && $data['assigned_to'] !== null && $data['assigned_to'] !== '') {
            $userModel = new User();
            if (!ctype_digit((string)$data['assigned_to']) || !$userModel->belongsToTenant((int)$data['assigned_to'], $tenantId)) {
                $errors['assigned_to'] = 'Unknown user';
            } else {
                $lead['assigned_to'] = (int)$data['assigned_to'];
            }
        }

        if (!empty($errors)) {
            $this->error('Validation failed', 422, array(), $errors);
        }

        if (!checkQuota($tenantId, 'leads')) {
            $this->error('Lead limit reached for your plan (or no active subscription). Please upgrade your plan.', 403);
        }

        $lead['tenant_id'] = $tenantId;
        $lead['status'] = 'new';
        if ($lead['source'] === null) {
            $lead['source'] = 'API';
        }

        try {
            $leadId = $this->leadModel->createLead($lead);
        } catch (Exception $e) {
            error_log('API lead create failed: ' . $e->getMessage());
            $leadId = false;
        }
        if (!$leadId) {
            $this->error('Failed to create lead', 500);
        }

        $row = $this->leadModel->getById($leadId, $tenantId);
        logActivity(null, $tenantId, 'created_via_api', 'lead', $leadId);
        dispatchEvent($tenantId, 'lead.created', $row);

        // Re-read so the response includes the freshly calculated score
        $created = $this->leadModel->getLeadWithUser($leadId, $tenantId);
        header('Location: ' . url('api/leads/show/' . $leadId));
        $this->respond(array(
            'success' => true,
            'message' => 'Lead created successfully',
            'data' => array(
                'lead_id' => (int)$leadId,
                'lead' => $this->present($created ? $created : $row)
            )
        ), 201);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Authenticate the request by API key; responds 401 on failure
     * @return array Tenant row
     */
    private function authenticate() {
        if (isset($_GET['api_key']) || isset($_GET['apikey']) || isset($_GET['key'])) {
            $this->error('API keys must be sent in the X-API-Key header, not the URL', 400);
        }
        $apiKey = $this->getApiKey();
        if ($apiKey === null || $apiKey === '') {
            $this->error('API key is required (X-API-Key header)', 401, array('WWW-Authenticate: Bearer'));
        }
        if (strlen($apiKey) > 128) {
            $this->error('Invalid API key', 401);
        }
        $tenant = verifyApiKey($apiKey);
        if (!$tenant) {
            // verifyApiKey() only returns active tenants
            $this->error('Invalid API key', 401);
        }
        return $tenant;
    }

    /**
     * API key from X-API-Key or Authorization: Bearer
     * @return string|null
     */
    private function getApiKey() {
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        $auth = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            foreach ((array)apache_request_headers() as $name => $value) {
                $lower = strtolower($name);
                if ($lower === 'x-api-key') {
                    return trim($value);
                }
                if ($lower === 'authorization') {
                    $auth = $value;
                }
            }
        }
        if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return null;
    }

    /**
     * Bounded integer query parameter
     */
    private function intParam($name, $default, $min, $max) {
        if (!isset($_GET[$name]) || $_GET[$name] === '') {
            return $default;
        }
        if (!is_string($_GET[$name]) || !ctype_digit($_GET[$name])) {
            $this->error('Validation failed', 422, array(), array($name => 'Must be a positive integer'));
        }
        return max($min, min($max, (int)$_GET[$name]));
    }

    /**
     * Shape a lead row for API output
     * @param array $row
     * @return array
     */
    private function present($row) {
        $out = array();
        foreach (self::$publicFields as $field) {
            $out[$field] = array_key_exists($field, $row) ? $row[$field] : null;
        }
        foreach (array('id', 'assigned_to', 'score') as $intField) {
            if ($out[$intField] !== null) {
                $out[$intField] = (int)$out[$intField];
            }
        }
        foreach (array('budget_min', 'budget_max') as $numField) {
            if ($out[$numField] !== null) {
                $out[$numField] = (float)$out[$numField];
            }
        }
        $out['tags'] = Lead::tagList($out['tags']);
        return $out;
    }

    /**
     * Send an error response and exit
     */
    private function error($message, $statusCode, $headers = array(), $errors = null) {
        foreach ($headers as $header) {
            header($header);
        }
        $body = array('success' => false, 'error' => $message);
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        $this->respond($body, $statusCode);
    }

    /**
     * Send JSON and exit
     */
    private function respond($data, $statusCode) {
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}

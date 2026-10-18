<?php
// FILE: /app/controllers/LeadsController.php

/**
 * SplashEstate CRM - Leads Controller
 * Manages lead operations
 */

class LeadsController extends Controller {

    private $leadModel;
    private $userModel;

    /**
     * Constructor
     */
    public function __construct() {
        $this->leadModel = $this->model('Lead');
        $this->userModel = $this->model('User');
    }

    /**
     * List all leads
     */
    public function index() {
        $this->requirePermission('leads.view');
        $tenantId = $this->requireTenant();

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = 20;

        $filters = array();
        foreach (array('status', 'assigned_to', 'source', 'interest_type', 'grade', 'tag', 'search',
                       'budget_min', 'budget_max', 'created_from', 'created_to', 'sort', 'direction') as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key]) && trim($_GET[$key]) !== '') {
                $filters[$key] = trim($_GET[$key]);
            }
        }
        if (isset($filters['status']) && !in_array($filters['status'], Lead::STATUSES, true)) {
            unset($filters['status']);
        }
        if (isset($filters['grade']) && !in_array($filters['grade'], array('A', 'B', 'C', 'D', 'F'), true)) {
            unset($filters['grade']);
        }

        $leads = $this->leadModel->getLeadsWithUser($tenantId, $page, $perPage, $filters);
        $totalLeads = $this->leadModel->countLeads($tenantId, $filters);
        $totalPages = (int)ceil($totalLeads / $perPage);

        // Agents for the filter dropdown (id => name)
        $agents = array();
        foreach ($this->userModel->getUsersByTenant($tenantId) as $agent) {
            $agents[$agent['id']] = $agent['first_name'] . ' ' . $agent['last_name'];
        }

        $data = array(
            'title' => 'Leads',
            'leads' => $leads,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalLeads' => $totalLeads,
            'agents' => $agents,
            'sources' => $this->leadModel->getSources($tenantId),
            'filters' => $filters
        );

        $this->view('leads/index', $data);
    }

    /**
     * View single lead (URL: /leads/view/{id})
     * @param int $id Lead ID
     */
    public function show($id) {
        $this->requirePermission('leads.view');
        $tenantId = $this->requireTenant();

        $lead = $this->leadModel->getLeadWithUser((int)$id, $tenantId);
        if (!$lead) {
            $this->setFlash('error', 'Lead not found');
            $this->redirect('leads/index');
        }
        $leadId = (int)$lead['id'];

        $activities = $this->model('Activity')->getByRelated($tenantId, 'lead', $leadId);
        $attachments = $this->model('Attachment')->getByRelated($tenantId, 'lead', $leadId);
        $tasks = $this->model('Task')->getByRelated($tenantId, 'lead', $leadId);

        // Lead scoring panel (never let a scoring failure break the page)
        $leadScore = null;
        $scoreHistory = array();
        $recommendations = array();
        $scoringError = false;
        try {
            require_once APP_PATH . '/helpers/LeadScoringEngine.php';
            $engine = new LeadScoringEngine();
            $leadScore = $engine->getLeadScore($leadId);
            $scoreHistory = $engine->getScoreHistory($leadId, 10);
            $recommendations = $engine->getRecommendedActions($leadId);
        } catch (Throwable $e) {
            error_log('Lead scoring panel failed for lead ' . $leadId . ': ' . $e->getMessage());
            $scoringError = true;
        }

        $data = array(
            'title' => 'Lead: ' . trim($lead['first_name'] . ' ' . (string)$lead['last_name']),
            'lead' => $lead,
            'tags' => Lead::tagList($lead['tags']),
            'activities' => $activities,
            'attachments' => $attachments,
            'tasks' => $tasks,
            'leadScore' => $leadScore ? $leadScore : null,
            'scoreHistory' => is_array($scoreHistory) ? $scoreHistory : array(),
            'recommendations' => is_array($recommendations) ? $recommendations : array(),
            'scoringError' => $scoringError,
            'convertedClient' => $this->leadModel->getConvertedClient($leadId, $tenantId)
        );

        $this->view('leads/view', $data);
    }

    /**
     * Create new lead (GET form, POST save)
     */
    public function create() {
        $this->requirePermission('leads.create');
        $tenantId = $this->requireTenant();

        if (!checkQuota($tenantId, 'leads')) {
            $this->setFlash('error', 'Lead limit reached for your plan (or no active subscription). Please upgrade your plan.');
            $this->redirect('leads/index');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processCreate($tenantId);
            return;
        }

        $this->view('leads/create', array(
            'title' => 'Create Lead',
            'agents' => $this->userModel->getUsersByTenant($tenantId, null, true),
            'errors' => array(),
            'formData' => array('source' => 'Website', 'assigned_to' => $this->getUserId())
        ));
    }

    /**
     * Process create form
     * @param int $tenantId Tenant ID
     */
    private function processCreate($tenantId) {
        $this->requirePostWithCsrf('leads/create');

        $formData = $this->collectForm(false);
        $errors = $this->validateForm($formData, $tenantId, false);

        if (!empty($errors)) {
            $this->view('leads/create', array(
                'title' => 'Create Lead',
                'agents' => $this->userModel->getUsersByTenant($tenantId, null, true),
                'errors' => $errors,
                'formData' => $formData
            ));
            return;
        }

        $formData['tenant_id'] = $tenantId;
        $formData['status'] = 'new';

        $leadId = $this->leadModel->createLead($formData);

        if ($leadId) {
            logActivity($this->getUserId(), $tenantId, 'created', 'lead', $leadId);
            $lead = $this->leadModel->getById($leadId, $tenantId);
            dispatchEvent($tenantId, 'lead.created', $lead);
            $this->notifyAssignee($tenantId, $lead, null);
            $this->setFlash('success', 'Lead created successfully');
            $this->redirect('leads/view/' . $leadId);
        }

        $this->setFlash('error', 'Failed to create lead');
        $this->redirect('leads/create');
    }

    /**
     * Edit lead (GET form, POST save)
     * @param int $id Lead ID
     */
    public function edit($id) {
        $this->requirePermission('leads.edit');
        $tenantId = $this->requireTenant();

        $lead = $this->leadModel->getById((int)$id, $tenantId);
        if (!$lead) {
            $this->setFlash('error', 'Lead not found');
            $this->redirect('leads/index');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processEdit($lead, $tenantId);
            return;
        }

        $this->view('leads/edit', array(
            'title' => 'Edit Lead',
            'lead' => $lead,
            'formData' => $lead,
            'agents' => $this->userModel->getUsersByTenant($tenantId, null, true),
            'errors' => array()
        ));
    }

    /**
     * Process edit form
     * @param array $lead Current lead row
     * @param int $tenantId Tenant ID
     */
    private function processEdit($lead, $tenantId) {
        $id = (int)$lead['id'];
        $this->requirePostWithCsrf('leads/edit/' . $id);

        $formData = $this->collectForm(true);
        $errors = $this->validateForm($formData, $tenantId, true, $lead);

        if (!empty($errors)) {
            $this->view('leads/edit', array(
                'title' => 'Edit Lead',
                'lead' => $lead,
                'formData' => array_merge($lead, $formData),
                'agents' => $this->userModel->getUsersByTenant($tenantId, null, true),
                'errors' => $errors
            ));
            return;
        }

        $update = $this->toDbValues($formData);

        if ($this->leadModel->update($id, $tenantId, $update)) {
            logActivity($this->getUserId(), $tenantId, 'updated', 'lead', $id);
            $updated = $this->leadModel->getById($id, $tenantId);
            $oldStatus = $lead['status'];

            $payload = $updated;
            $payload['old_status'] = $oldStatus;
            dispatchEvent($tenantId, 'lead.updated', $payload);
            if ($updated['status'] !== $oldStatus) {
                dispatchEvent($tenantId, 'lead.status_changed', $payload);
            }
            $this->notifyAssignee($tenantId, $updated, $lead['assigned_to']);

            $this->setFlash('success', 'Lead updated successfully');
            $this->redirect('leads/view/' . $id);
        }

        $this->setFlash('error', 'Failed to update lead');
        $this->redirect('leads/edit/' . $id);
    }

    /**
     * Delete lead (POST + CSRF)
     * @param int $id Lead ID
     */
    public function delete($id) {
        $this->requirePermission('leads.delete');
        $tenantId = $this->requireTenant();
        $this->requirePostWithCsrf('leads/index');

        $lead = $this->leadModel->getById((int)$id, $tenantId);
        if (!$lead) {
            $this->setFlash('error', 'Lead not found');
            $this->redirect('leads/index');
        }

        if ($this->leadModel->delete((int)$lead['id'], $tenantId)) {
            logActivity($this->getUserId(), $tenantId, 'deleted', 'lead', $lead['id']);
            dispatchEvent($tenantId, 'lead.deleted', $lead);
            $this->setFlash('success', 'Lead deleted successfully');
        } else {
            $this->setFlash('error', 'Failed to delete lead');
        }

        $this->redirect('leads/index');
    }

    /**
     * Convert lead to client (POST + CSRF)
     * @param int $id Lead ID
     */
    public function convert($id) {
        $this->requirePermission('clients.create');
        $tenantId = $this->requireTenant();
        $this->requirePostWithCsrf('leads/view/' . (int)$id);

        if (!can('leads.edit')) {
            $this->setFlash('error', 'You do not have permission to perform this action.');
            $this->redirect('leads/view/' . (int)$id);
        }

        $lead = $this->leadModel->getById((int)$id, $tenantId);
        if (!$lead) {
            $this->setFlash('error', 'Lead not found');
            $this->redirect('leads/index');
        }
        $leadId = (int)$lead['id'];

        $existing = $this->leadModel->getConvertedClient($leadId, $tenantId);
        if ($existing) {
            $this->setFlash('info', 'This lead has already been converted to a client.');
            $this->redirect('clients/view/' . $existing['id']);
        }

        $clientId = $this->leadModel->convertToClient($leadId, $tenantId);

        if ($clientId) {
            logActivity($this->getUserId(), $tenantId, 'converted', 'lead', $leadId);
            logActivity($this->getUserId(), $tenantId, 'created', 'client', $clientId);

            $updated = $this->leadModel->getById($leadId, $tenantId);
            if ($updated && $updated['status'] !== $lead['status']) {
                $payload = $updated;
                $payload['old_status'] = $lead['status'];
                dispatchEvent($tenantId, 'lead.status_changed', $payload);
            }
            $client = $this->model('Client')->getById($clientId, $tenantId);
            dispatchEvent($tenantId, 'client.created', $client);

            $this->setFlash('success', 'Lead converted to client successfully');
            $this->redirect('clients/view/' . $clientId);
        }

        $this->setFlash('error', 'Failed to convert lead');
        $this->redirect('leads/view/' . $leadId);
    }

    /**
     * Recalculate a lead's score now (POST + CSRF)
     * @param int $id Lead ID
     */
    public function rescore($id) {
        $this->requirePermission('leads.edit');
        $tenantId = $this->requireTenant();
        $this->requirePostWithCsrf('leads/view/' . (int)$id);

        $lead = $this->leadModel->getById((int)$id, $tenantId);
        if (!$lead) {
            $this->setFlash('error', 'Lead not found');
            $this->redirect('leads/index');
        }

        try {
            require_once APP_PATH . '/helpers/LeadScoringEngine.php';
            $engine = new LeadScoringEngine();
            $engine->calculateScore((int)$lead['id'], 'manual');
            $this->setFlash('success', 'Lead score recalculated');
        } catch (Throwable $e) {
            error_log('Manual rescore failed for lead ' . $lead['id'] . ': ' . $e->getMessage());
            $this->setFlash('error', 'Could not recalculate the lead score');
        }

        $this->redirect('leads/view/' . (int)$lead['id']);
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
            $this->setFlash('error', 'Leads belong to an agency account. Log in as an agency user to manage leads.');
            $this->redirect('dashboard/index');
        }
        return (int)$tenantId;
    }

    /**
     * Trimmed string from POST ('' when missing or not a string)
     */
    private function post($key) {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    }

    /**
     * Collect lead form fields (raw, trimmed; escaping happens on output)
     * @param bool $withStatus Include the status field (edit form)
     * @return array
     */
    private function collectForm($withStatus) {
        $data = array();
        foreach (array('first_name', 'last_name', 'email', 'phone', 'source', 'interest_type',
                       'budget_min', 'budget_max', 'assigned_to', 'notes', 'tags') as $field) {
            $data[$field] = $this->post($field);
        }
        if ($withStatus) {
            $data['status'] = $this->post('status');
        }
        $data['tags'] = (string)Lead::normalizeTags($data['tags']);
        return $data;
    }

    /**
     * Validate lead form data
     * @param array $data Form data
     * @param int $tenantId Tenant ID
     * @param bool $isEdit Whether status is part of the form
     * @param array|null $current Current lead row (edit)
     * @return array field => error
     */
    private function validateForm($data, $tenantId, $isEdit, $current = null) {
        $errors = validateRequired($data, array('first_name'));

        foreach (array('first_name' => 100, 'last_name' => 100, 'email' => 255, 'phone' => 50, 'source' => 100) as $field => $max) {
            if (strlen($data[$field]) > $max) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be at most {$max} characters";
            }
        }
        if ($data['email'] !== '' && !$this->validateEmail($data['email'])) {
            $errors['email'] = 'Invalid email format';
        }
        if ($data['interest_type'] !== '' && !in_array($data['interest_type'], Lead::INTEREST_TYPES, true)) {
            $errors['interest_type'] = 'Invalid interest type';
        }
        if ($isEdit && !in_array($data['status'], Lead::STATUSES, true)) {
            $errors['status'] = 'Invalid status';
        }
        foreach (array('budget_min', 'budget_max') as $field) {
            if ($data[$field] !== '' && (!is_numeric($data[$field]) || (float)$data[$field] < 0)) {
                $errors[$field] = 'Budget must be a positive number';
            }
        }
        if (!isset($errors['budget_min']) && !isset($errors['budget_max'])
            && $data['budget_min'] !== '' && $data['budget_max'] !== ''
            && (float)$data['budget_min'] > (float)$data['budget_max']) {
            $errors['budget_max'] = 'Maximum budget must be greater than the minimum';
        }
        if ($data['assigned_to'] !== '') {
            $unchanged = $current !== null && (string)$current['assigned_to'] === $data['assigned_to'];
            if (!ctype_digit($data['assigned_to'])
                || (!$unchanged && !$this->userModel->belongsToTenant((int)$data['assigned_to'], $tenantId))) {
                $errors['assigned_to'] = 'Please choose an agent from your team';
            }
        }
        return $errors;
    }

    /**
     * Convert validated form data to DB values (empty strings -> NULL)
     * @param array $data
     * @return array
     */
    private function toDbValues($data) {
        foreach ($data as $key => $value) {
            if ($value === '' && $key !== 'first_name') {
                $data[$key] = null;
            }
        }
        if ($data['assigned_to'] !== null) {
            $data['assigned_to'] = (int)$data['assigned_to'];
        }
        return $data;
    }

    /**
     * Notify a lead's assignee when it was (re)assigned to someone else
     * @param int $tenantId Tenant ID
     * @param array $lead Lead row after save
     * @param int|null $previousAssignee Assignee before save
     */
    private function notifyAssignee($tenantId, $lead, $previousAssignee) {
        if (empty($lead['assigned_to'])
            || (int)$lead['assigned_to'] === (int)$this->getUserId()
            || (int)$lead['assigned_to'] === (int)$previousAssignee) {
            return;
        }
        $name = trim($lead['first_name'] . ' ' . (string)$lead['last_name']);
        createNotification($tenantId, (int)$lead['assigned_to'], 'New lead assigned to you',
            $name . ' was assigned to you by ' . (isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'a colleague') . '.',
            'leads/view/' . $lead['id'], 'lead');
    }
}

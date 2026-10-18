<?php
// FILE: /app/controllers/DealsController.php

/**
 * SplashEstate CRM - Deals Controller
 * Pipeline (Kanban board / table), deal CRUD and stage moves.
 * Detail URL: /deals/view/{id} (routed to show()).
 */

class DealsController extends Controller {

    private $dealModel;
    private $clientModel;
    private $propertyModel;
    private $userModel;

    public function __construct() {
        $this->dealModel = $this->model('Deal');
        $this->clientModel = $this->model('Client');
        $this->propertyModel = $this->model('Property');
        $this->userModel = $this->model('User');
    }

    /**
     * Pipeline board (default) or table view (?view=table)
     */
    public function index() {
        $this->requirePermission('deals.view');

        $tenantId = $this->getTenantId();
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $perPage = 20;
        $mode = (isset($_GET['view']) && $_GET['view'] === 'table') ? 'table' : 'board';

        $filters = array();
        foreach (array('stage', 'assigned_to', 'search') as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key]) && trim($_GET[$key]) !== '') {
                $filters[$key] = trim($_GET[$key]);
            }
        }

        // Pipeline totals always cover the whole (filtered) pipeline
        $pipeline = $this->dealModel->getPipeline($tenantId, $filters);
        $summary = array('count' => 0, 'total' => 0.0, 'weighted' => 0.0, 'open_count' => 0, 'open_total' => 0.0, 'open_weighted' => 0.0, 'won_total' => 0.0);
        foreach ($pipeline as $stage => $column) {
            $summary['count'] += $column['count'];
            $summary['total'] += $column['total'];
            $summary['weighted'] += $column['weighted'];
            if ($stage !== 'closed_won' && $stage !== 'closed_lost') {
                $summary['open_count'] += $column['count'];
                $summary['open_total'] += $column['total'];
                $summary['open_weighted'] += $column['weighted'];
            }
            if ($stage === 'closed_won') {
                $summary['won_total'] += $column['total'];
            }
        }

        $deals = array();
        $totalPages = 0;
        if ($mode === 'table') {
            $totalDeals = $this->dealModel->count($tenantId, $filters);
            $totalPages = (int)ceil($totalDeals / $perPage);
            $deals = $this->dealModel->getDealsWithInfo($tenantId, $page, $perPage, $filters);
        }

        $this->view('deals/index', array(
            'title' => 'Deals',
            'mode' => $mode,
            'deals' => $deals,
            'pipeline' => $pipeline,
            'summary' => $summary,
            'stages' => Deal::getStages(),
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'agents' => $this->userModel->getUsersByTenant($tenantId),
            'filters' => $filters,
            'canEdit' => can('deals.edit')
        ));
    }

    /**
     * Deal detail page (URL: deals/view/{id})
     * @param int $id Deal ID
     */
    public function show($id) {
        $this->requirePermission('deals.view');

        $tenantId = $this->getTenantId();
        $deal = $this->dealModel->getDealWithInfo((int)$id, $tenantId);
        if (!$deal) {
            $this->setFlash('error', 'Deal not found');
            $this->redirect('deals/index');
        }

        $this->view('deals/view', array(
            'title' => $deal['title'],
            'deal' => $deal,
            'stages' => Deal::getStages(),
            'tasks' => $this->dealModel->getRelatedTasks($deal['id'], $tenantId),
            'history' => $this->dealModel->getStageHistory($deal['id'], $tenantId),
            'canEdit' => can('deals.edit'),
            'canDelete' => can('deals.delete')
        ));
    }

    /**
     * Create form / handler
     */
    public function create() {
        $this->requirePermission('deals.create');

        $tenantId = $this->getTenantId();
        $formData = array('stage' => 'lead', 'probability' => 10, 'assigned_to' => $this->getUserId());
        foreach (array('client_id', 'property_id') as $key) {
            if (isset($_GET[$key]) && ctype_digit((string)$_GET[$key])) {
                $formData[$key] = (int)$_GET[$key];
            }
        }
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('deals/create');

            list($formData, $errors) = $this->collectInput($tenantId);

            if (empty($errors)) {
                $formData['tenant_id'] = $tenantId;
                if ($formData['stage'] === 'closed_won' || $formData['stage'] === 'closed_lost') {
                    $formData['actual_close_date'] = date('Y-m-d');
                }

                $dealId = $this->dealModel->createDeal($formData);
                if ($dealId) {
                    logActivity($this->getUserId(), $tenantId, 'created', 'deal', $dealId);
                    $saved = $this->dealModel->getById($dealId, $tenantId);
                    $saved = $saved ? $saved : array('id' => $dealId);
                    dispatchEvent($tenantId, 'deal.created', $saved);
                    if ($formData['stage'] === 'closed_won') {
                        dispatchEvent($tenantId, 'deal.won', $saved);
                    } elseif ($formData['stage'] === 'closed_lost') {
                        dispatchEvent($tenantId, 'deal.lost', $saved);
                    }
                    $this->setFlash('success', 'Deal created successfully');
                    $this->redirect('deals/view/' . $dealId);
                }
                $errors['general'] = 'Failed to create deal. Please try again.';
            }
        }

        $this->view('deals/create', array_merge($this->formOptions($tenantId), array(
            'title' => 'New Deal',
            'errors' => $errors,
            'formData' => $formData
        )));
    }

    /**
     * Edit form / handler
     * @param int $id Deal ID
     */
    public function edit($id) {
        $this->requirePermission('deals.edit');

        $tenantId = $this->getTenantId();
        $deal = $this->dealModel->getById((int)$id, $tenantId);
        if (!$deal) {
            $this->setFlash('error', 'Deal not found');
            $this->redirect('deals/index');
        }

        $formData = $deal;
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('deals/edit/' . (int)$deal['id']);

            list($input, $errors) = $this->collectInput($tenantId);
            $formData = array_merge($deal, $input);

            if (empty($errors)) {
                $newStage = $input['stage'];
                unset($input['stage']); // stage changes go through applyStageChange()

                if ($this->dealModel->update($deal['id'], $tenantId, $input)) {
                    logActivity($this->getUserId(), $tenantId, 'updated', 'deal', $deal['id']);
                    if ($newStage !== $deal['stage']) {
                        $this->applyStageChange($deal, $newStage, $tenantId);
                    }
                    $saved = $this->dealModel->getById($deal['id'], $tenantId);
                    dispatchEvent($tenantId, 'deal.updated', $saved ? $saved : array('id' => (int)$deal['id']));
                    $this->setFlash('success', 'Deal updated successfully');
                    $this->redirect('deals/view/' . (int)$deal['id']);
                }
                $errors['general'] = 'Failed to update deal. Please try again.';
            }
        }

        $this->view('deals/edit', array_merge($this->formOptions($tenantId), array(
            'title' => 'Edit Deal',
            'deal' => $deal,
            'errors' => $errors,
            'formData' => $formData
        )));
    }

    /**
     * Move a deal to another stage. POST + CSRF.
     * Accepts form field / JSON body "stage". Responds with JSON for AJAX
     * requests, otherwise redirects back (to "return" = board|view).
     * @param int $id Deal ID
     */
    public function updateStage($id) {
        $this->requirePermission('deals.edit');
        $this->requirePostWithCsrf('deals/index');

        $tenantId = $this->getTenantId();
        $json = $this->wantsJson();

        $stage = isset($_POST['stage']) ? $_POST['stage'] : null;
        if ($stage === null) {
            $input = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($input) && isset($input['stage'])) {
                $stage = $input['stage'];
            }
        }

        $returnTo = (isset($_POST['return']) && $_POST['return'] === 'view') ? 'deals/view/' . (int)$id : 'deals/index';

        if (!is_string($stage) || !Deal::isValidStage($stage)) {
            if ($json) {
                $this->jsonResponse(array('success' => false, 'message' => 'Invalid stage'), 422);
            }
            $this->setFlash('error', 'Invalid stage');
            $this->redirect($returnTo);
        }

        $deal = $this->dealModel->getById((int)$id, $tenantId);
        if (!$deal) {
            if ($json) {
                $this->jsonResponse(array('success' => false, 'message' => 'Deal not found'), 404);
            }
            $this->setFlash('error', 'Deal not found');
            $this->redirect('deals/index');
        }

        $changed = $deal['stage'] !== $stage;
        $ok = $changed ? $this->applyStageChange($deal, $stage, $tenantId) : true;
        $saved = $this->dealModel->getById($deal['id'], $tenantId);

        if ($json) {
            $this->jsonResponse(array(
                'success' => (bool)$ok,
                'changed' => $changed,
                'deal' => $saved ? array(
                    'id' => (int)$saved['id'],
                    'stage' => $saved['stage'],
                    'probability' => (int)$saved['probability'],
                    'deal_value' => (float)$saved['deal_value'],
                    'actual_close_date' => $saved['actual_close_date']
                ) : null
            ), $ok ? 200 : 500);
        }

        $stages = Deal::getStages();
        if ($ok) {
            $this->setFlash('success', $changed ? 'Deal moved to ' . $stages[$stage] : 'Deal is already in ' . $stages[$stage]);
        } else {
            $this->setFlash('error', 'Failed to update the deal stage');
        }
        $this->redirect($returnTo);
    }

    /**
     * Delete (POST + CSRF only)
     * @param int $id Deal ID
     */
    public function delete($id) {
        $this->requirePermission('deals.delete');
        $this->requirePostWithCsrf('deals/index');

        $tenantId = $this->getTenantId();
        $deal = $this->dealModel->getById((int)$id, $tenantId);
        if (!$deal) {
            $this->setFlash('error', 'Deal not found');
            $this->redirect('deals/index');
        }

        if ($this->dealModel->delete($deal['id'], $tenantId)) {
            logActivity($this->getUserId(), $tenantId, 'deleted', 'deal', $deal['id']);
            dispatchEvent($tenantId, 'deal.deleted', $deal);
            $this->setFlash('success', 'Deal deleted successfully');
        } else {
            $this->setFlash('error', 'Failed to delete deal');
        }

        $this->redirect('deals/index');
    }

    /**
     * Persist a stage change, log it (stage history) and raise
     * deal.stage_changed plus deal.won / deal.lost.
     * @param array $deal Deal row before the change
     * @param string $newStage New stage
     * @param int $tenantId Tenant ID
     * @return bool
     */
    private function applyStageChange($deal, $newStage, $tenantId) {
        if (!$this->dealModel->updateStage($deal['id'], $tenantId, $newStage)) {
            return false;
        }

        $oldStage = $deal['stage'];
        logActivity($this->getUserId(), $tenantId, 'stage_changed:' . $oldStage . ':' . $newStage, 'deal', $deal['id']);

        $saved = $this->dealModel->getById($deal['id'], $tenantId);
        $payload = $saved ? $saved : array_merge($deal, array('stage' => $newStage));
        $payload['old_stage'] = $oldStage;

        dispatchEvent($tenantId, 'deal.stage_changed', $payload);
        if ($newStage === 'closed_won') {
            dispatchEvent($tenantId, 'deal.won', $payload);
            $this->notifyAssignee($payload, $tenantId, 'Deal won', 'success');
        } elseif ($newStage === 'closed_lost') {
            dispatchEvent($tenantId, 'deal.lost', $payload);
            $this->notifyAssignee($payload, $tenantId, 'Deal lost', 'warning');
        }
        return true;
    }

    /**
     * In-app notification for the deal's assignee (when it is not the current user)
     */
    private function notifyAssignee($deal, $tenantId, $title, $type) {
        if (!empty($deal['assigned_to']) && (int)$deal['assigned_to'] !== (int)$this->getUserId()) {
            createNotification($tenantId, (int)$deal['assigned_to'], $title, $deal['title'], 'deals/view/' . (int)$deal['id'], $type);
        }
    }

    /**
     * Dropdown data for the deal form
     * @param int $tenantId
     * @return array
     */
    private function formOptions($tenantId) {
        return array(
            'clients' => $this->clientModel->getAllForTenant($tenantId, 'first_name'),
            'properties' => $this->propertyModel->getOptions($tenantId),
            'agents' => $this->userModel->getUsersByTenant($tenantId),
            'stages' => Deal::getStages()
        );
    }

    /**
     * Read and validate the deal form (raw trimmed values; escaped on output)
     * @param int $tenantId
     * @return array array($data, $errors)
     */
    private function collectInput($tenantId) {
        $str = function ($key, $max = 255) {
            $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
            return mb_substr($value, 0, $max);
        };

        $data = array(
            'title' => $str('title'),
            'client_id' => $str('client_id', 11),
            'property_id' => $str('property_id', 11),
            'assigned_to' => $str('assigned_to', 11),
            'deal_value' => str_replace(array(',', '$', ' '), '', $str('deal_value', 20)),
            'commission' => str_replace(array(',', '$', ' '), '', $str('commission', 20)),
            'stage' => $str('stage', 20),
            'probability' => $str('probability', 3),
            'expected_close_date' => $str('expected_close_date', 10),
            'notes' => $str('notes', 65000)
        );

        $errors = validateRequired($data, array('title'));

        if ($data['stage'] === '') {
            $data['stage'] = 'lead';
        } elseif (!Deal::isValidStage($data['stage'])) {
            $errors['stage'] = 'Invalid stage';
        }

        foreach (array('deal_value', 'commission') as $field) {
            if ($data[$field] === '') {
                $data[$field] = null;
            } elseif (!is_numeric($data[$field]) || (float)$data[$field] < 0 || (float)$data[$field] > 9999999999999) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' must be a positive number';
            } else {
                $data[$field] = (float)$data[$field];
            }
        }

        if ($data['probability'] === '') {
            $data['probability'] = 0;
        } elseif (!ctype_digit($data['probability']) || (int)$data['probability'] > 100) {
            $errors['probability'] = 'Probability must be between 0 and 100';
        } else {
            $data['probability'] = (int)$data['probability'];
        }

        if ($data['expected_close_date'] === '') {
            $data['expected_close_date'] = null;
        } else {
            $d = DateTime::createFromFormat('Y-m-d', $data['expected_close_date']);
            if (!$d || $d->format('Y-m-d') !== $data['expected_close_date']) {
                $errors['expected_close_date'] = 'Expected close date is invalid';
            }
        }

        if ($data['notes'] === '') {
            $data['notes'] = null;
        }

        // Related records must belong to this tenant
        $refs = array(
            'client_id' => array($this->clientModel, 'Selected client was not found'),
            'property_id' => array($this->propertyModel, 'Selected property was not found')
        );
        foreach ($refs as $field => $ref) {
            if ($data[$field] === '') {
                $data[$field] = null;
            } elseif (!ctype_digit($data[$field]) || !$ref[0]->getById((int)$data[$field], $tenantId)) {
                $errors[$field] = $ref[1];
                $data[$field] = null;
            } else {
                $data[$field] = (int)$data[$field];
            }
        }

        if ($data['assigned_to'] === '') {
            $data['assigned_to'] = null;
        } else {
            $found = false;
            if (ctype_digit($data['assigned_to'])) {
                foreach ($this->userModel->getUsersByTenant($tenantId) as $user) {
                    if ((int)$user['id'] === (int)$data['assigned_to']) {
                        $found = true;
                        break;
                    }
                }
            }
            if ($found) {
                $data['assigned_to'] = (int)$data['assigned_to'];
            } else {
                $errors['assigned_to'] = 'Selected agent was not found';
                $data['assigned_to'] = null;
            }
        }

        return array($data, $errors);
    }
}

<?php
// FILE: /app/controllers/ScoringController.php

/**
 * SplashEstate CRM - Lead Scoring Controller
 * Manage scoring rules, recalculate scores, scoring dashboard and rule tester.
 * Requires the scoring.manage permission; all data is scoped to the
 * current tenant.
 */

require_once APP_PATH . '/helpers/LeadScoringEngine.php';

class ScoringController extends Controller {

    private $ruleModel;
    private $scoreModel;

    public function __construct() {
        $this->ruleModel = $this->model('LeadScoringRule');
        $this->scoreModel = $this->model('LeadScore');
    }

    /**
     * Rules grouped by type, rule stats and scoring dashboard
     */
    public function index() {
        $tenantId = $this->requireScoringAccess();
        $engine = new LeadScoringEngine();

        $this->view('scoring/index', array(
            'title' => 'Lead Scoring',
            'groupedRules' => $this->ruleModel->getGroupedByType($tenantId),
            'ruleTypes' => LeadScoringRule::getRuleTypes(),
            'operators' => LeadScoringRule::getOperators(),
            'fields' => LeadScoringRule::getAllowedFields(),
            'metrics' => $engine->getPerformanceMetrics($tenantId),
            'topLeads' => $this->scoreModel->getTopLeads($tenantId, 10)
        ));
    }

    /**
     * Create rule form (GET) / save (POST)
     */
    public function create() {
        $tenantId = $this->requireScoringAccess();
        $formData = array(
            'name' => '', 'description' => '', 'rule_type' => 'demographic', 'field_name' => 'source',
            'operator' => 'equals', 'field_value' => '', 'points' => '10', 'execution_order' => '0', 'status' => 'active'
        );
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('scoring/create');
            $formData = $this->readForm();
            $errors = LeadScoringRule::validate($formData);
            if (empty($errors)) {
                $data = $this->toRow($formData);
                $data['tenant_id'] = $tenantId;
                $ruleId = $this->ruleModel->create($data);
                if ($ruleId) {
                    logActivity($this->getUserId(), $tenantId, 'created', 'scoring_rule', $ruleId);
                    $this->setFlash('success', 'Scoring rule created. Recalculate scores to apply it to existing leads.');
                    $this->redirect('scoring/index');
                }
                $errors['general'] = 'Could not save the rule.';
            }
        }

        $this->renderForm('Create Scoring Rule', $formData, $errors, null);
    }

    /**
     * Edit rule form (GET) / save (POST)
     * @param int $id Rule ID
     */
    public function edit($id) {
        $tenantId = $this->requireScoringAccess();
        $rule = $this->ruleModel->getById((int)$id, $tenantId);
        if (!$rule) {
            $this->setFlash('error', 'Scoring rule not found.');
            $this->redirect('scoring/index');
        }

        $formData = $rule;
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('scoring/edit/' . (int)$id);
            $formData = $this->readForm();
            $errors = LeadScoringRule::validate($formData);
            if (empty($errors)) {
                if ($this->ruleModel->update((int)$id, $tenantId, $this->toRow($formData))) {
                    logActivity($this->getUserId(), $tenantId, 'updated', 'scoring_rule', (int)$id);
                    $this->setFlash('success', 'Scoring rule updated. Recalculate scores to apply the change to existing leads.');
                    $this->redirect('scoring/index');
                }
                $errors['general'] = 'Could not save the rule.';
            }
        }

        $this->renderForm('Edit Scoring Rule', $formData, $errors, (int)$id);
    }

    /**
     * Delete a rule (POST)
     * @param int $id Rule ID
     */
    public function delete($id) {
        $tenantId = $this->requireScoringAccess();
        $this->requirePostWithCsrf('scoring/index');

        $rule = $this->ruleModel->getById((int)$id, $tenantId);
        if (!$rule) {
            $this->setFlash('error', 'Scoring rule not found.');
            $this->redirect('scoring/index');
        }
        $this->ruleModel->delete((int)$id, $tenantId);
        logActivity($this->getUserId(), $tenantId, 'deleted', 'scoring_rule', (int)$id);
        $this->setFlash('success', 'Scoring rule "' . $rule['name'] . '" deleted.');
        $this->redirect('scoring/index');
    }

    /**
     * Toggle a rule active/inactive (POST)
     * @param int $id Rule ID
     */
    public function toggle($id) {
        $tenantId = $this->requireScoringAccess();
        $this->requirePostWithCsrf('scoring/index');

        $status = $this->ruleModel->toggleStatus((int)$id, $tenantId);
        if ($status === false) {
            if ($this->wantsJson()) {
                $this->jsonResponse(array('success' => false, 'message' => 'Rule not found'), 404);
            }
            $this->setFlash('error', 'Scoring rule not found.');
            $this->redirect('scoring/index');
        }
        logActivity($this->getUserId(), $tenantId, $status === 'active' ? 'activated' : 'deactivated', 'scoring_rule', (int)$id);
        if ($this->wantsJson()) {
            $this->jsonResponse(array('success' => true, 'status' => $status));
        }
        $this->setFlash('success', 'Rule ' . ($status === 'active' ? 'activated' : 'deactivated') . '.');
        $this->redirect('scoring/index');
    }

    /**
     * Recalculate every lead score of the tenant (POST)
     */
    public function recalculate() {
        $tenantId = $this->requireScoringAccess();
        $this->requirePostWithCsrf('scoring/index');

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $engine = new LeadScoringEngine();
        $result = $engine->bulkRecalculate($tenantId);
        logActivity($this->getUserId(), $tenantId, 'recalculated_scores', 'lead_score', null);

        if ($this->wantsJson()) {
            $this->jsonResponse(array('success' => $result['failed'] === 0, 'result' => $result));
        }
        if ($result['failed'] > 0) {
            $this->setFlash('warning', sprintf('Recalculated %d of %d leads; %d failed (see server log).', $result['success'], $result['total'], $result['failed']));
        } else {
            $this->setFlash('success', sprintf('Recalculated scores for %d lead(s).', $result['success']));
        }
        $this->redirect('scoring/index');
    }

    /**
     * Test the active rules against a lead: scoring/test?lead_id=5 or scoring/test/5
     * @param int|null $leadId Lead ID
     */
    public function test($leadId = null) {
        $tenantId = $this->requireScoringAccess();
        if ($leadId === null && isset($_GET['lead_id']) && $_GET['lead_id'] !== '') {
            $leadId = $_GET['lead_id'];
        }

        $result = null;
        $current = null;
        $error = null;
        if ($leadId !== null) {
            $engine = new LeadScoringEngine();
            $result = $engine->testRules((int)$leadId, $tenantId);
            if ($result === null) {
                $error = 'Lead #' . (int)$leadId . ' was not found.';
            } else {
                $current = $engine->getLeadScore((int)$leadId);
            }
        }

        $stmt = $this->ruleModel->getConnection()->prepare(
            "SELECT id, first_name, last_name FROM leads WHERE tenant_id = :tenant_id ORDER BY created_at DESC, id DESC LIMIT 200"
        );
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();

        $this->view('scoring/test', array(
            'title' => 'Test Scoring Rules',
            'leads' => $stmt->fetchAll(),
            'leadId' => $leadId !== null ? (int)$leadId : null,
            'result' => $result,
            'current' => $current,
            'error' => $error,
            'operators' => LeadScoringRule::getOperators(),
            'fields' => LeadScoringRule::getAllowedFields()
        ));
    }

    /**
     * Require scoring.manage and a tenant context. Returns the tenant ID.
     * @return int
     */
    private function requireScoringAccess() {
        $this->requirePermission('scoring.manage');
        $tenantId = $this->getTenantId();
        if (empty($tenantId)) {
            $this->setFlash('info', 'Lead scoring is configured per organization. Sign in as an organization user to manage it.');
            $this->redirect('dashboard/index');
        }
        return (int)$tenantId;
    }

    /**
     * Raw (trimmed) rule input from POST
     * @return array
     */
    private function readForm() {
        $fields = array('name', 'description', 'rule_type', 'field_name', 'operator', 'field_value', 'points', 'execution_order', 'status');
        $out = array();
        foreach ($fields as $field) {
            $out[$field] = isset($_POST[$field]) && is_string($_POST[$field]) ? trim($_POST[$field]) : '';
        }
        if ($out['execution_order'] === '') {
            $out['execution_order'] = '0';
        }
        $out['status'] = $out['status'] === 'inactive' ? 'inactive' : 'active';
        return $out;
    }

    /**
     * Validated form data -> DB row
     * @param array $form
     * @return array
     */
    private function toRow($form) {
        $noValue = in_array($form['operator'], array('is_empty', 'is_not_empty'), true);
        return array(
            'name' => $form['name'],
            'description' => $form['description'] !== '' ? $form['description'] : null,
            'rule_type' => $form['rule_type'],
            'field_name' => $form['field_name'],
            'operator' => $form['operator'],
            'field_value' => $noValue ? null : $form['field_value'],
            'points' => (int)$form['points'],
            'execution_order' => (int)$form['execution_order'],
            'status' => $form['status']
        );
    }

    /**
     * Render the create/edit form
     */
    private function renderForm($title, $formData, $errors, $ruleId) {
        $this->view('scoring/form', array(
            'title' => $title,
            'formData' => $formData,
            'errors' => $errors,
            'ruleId' => $ruleId,
            'ruleTypes' => LeadScoringRule::getRuleTypes(),
            'operators' => LeadScoringRule::getOperators(),
            'fields' => LeadScoringRule::getAllowedFields()
        ));
    }
}

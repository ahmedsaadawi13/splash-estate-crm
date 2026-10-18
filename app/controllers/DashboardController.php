<?php
// FILE: /app/controllers/DashboardController.php

/**
 * SplashEstate CRM - Dashboard Controller
 * Displays main dashboard with statistics and overview
 */

class DashboardController extends Controller {

    /**
     * Display dashboard (all figures are scoped to the user's tenant)
     */
    public function index() {
        $this->requireLogin();

        $tenantId = $this->getTenantId();
        $userId = (int)$this->getUserId();

        // Platform admins have no tenant: there is no agency data to summarise
        if (empty($tenantId)) {
            $this->view('dashboard/index', array(
                'title' => 'Dashboard',
                'noTenant' => true,
                'stats' => array(),
                'recent_leads' => array(),
                'recent_deals' => array(),
                'upcoming_tasks' => array(),
                'overdue_tasks' => array(),
                'hot_leads' => array(),
                'subscription' => false,
                'usage' => array()
            ));
            return;
        }
        $tenantId = (int)$tenantId;

        $leadModel = $this->model('Lead');
        $clientModel = $this->model('Client');
        $propertyModel = $this->model('Property');
        $dealModel = $this->model('Deal');
        $taskModel = $this->model('Task');

        $stats = array(
            'total_leads' => $leadModel->countLeads($tenantId),
            'new_leads' => $leadModel->countLeads($tenantId, array('status' => 'new')),
            'total_clients' => $clientModel->count($tenantId),
            'total_properties' => $propertyModel->count($tenantId, array()),
            'available_properties' => $propertyModel->count($tenantId, array('status' => 'available')),
            'total_deals' => $dealModel->count($tenantId, array()),
            'active_deals' => (int)$dealModel->getActiveDealsCount($tenantId),
            'open_tasks' => $taskModel->count($tenantId, array('status' => 'open')),
            'my_tasks' => $taskModel->count($tenantId, array('status' => 'open', 'assigned_to' => $userId)),
            'my_overdue' => $taskModel->countOverdueForUser($tenantId, $userId)
        );

        // Hot leads: agents see their own, admins see the whole agency
        $hotLeadsUser = $this->getUserRole() === 'agent' ? $userId : null;

        $subscriptionModel = $this->model('Subscription');

        $this->view('dashboard/index', array(
            'title' => 'Dashboard',
            'noTenant' => false,
            'stats' => $stats,
            'recent_leads' => $leadModel->getLeadsWithUser($tenantId, 1, 5),
            'recent_deals' => $dealModel->getAll($tenantId, 1, 5),
            'upcoming_tasks' => $taskModel->getUpcoming($tenantId, $userId, 5),
            'overdue_tasks' => $taskModel->getOverdueForUser($tenantId, $userId, 5),
            'hot_leads' => $leadModel->getHotLeads($tenantId, 5, $hotLeadsUser),
            'subscription' => $subscriptionModel->getActiveSubscription($tenantId),
            'usage' => $subscriptionModel->getUsageStats($tenantId)
        ));
    }
}

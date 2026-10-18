<?php
// FILE: /app/controllers/ReportsController.php

/**
 * SplashEstate CRM - Reports Controller
 * Reporting & analytics. Every page accepts ?start_date=Y-m-d&end_date=Y-m-d
 * (validated, default: last 90 days). Pages need reports.view; CSV export
 * needs reports.export.
 */

class ReportsController extends Controller {

    private $reportService;

    /** CSV export definitions: type => [title, columns] */
    private static $exports = array(
        'funnel' => array('Sales Funnel', array(
            'label' => 'Status', 'count' => 'Leads', 'percent_of_total' => '% of Leads',
            'reached' => 'Reached Stage', 'stage_conversion' => 'Stage Conversion %')),
        'pipeline' => array('Deal Pipeline', array(
            'label' => 'Stage', 'deal_count' => 'Deals', 'total_value' => 'Total Value',
            'weighted_value' => 'Weighted Value', 'avg_probability' => 'Avg Probability %')),
        'revenue' => array('Revenue by Month', array(
            'month' => 'Month', 'deal_count' => 'Deals Won', 'revenue' => 'Revenue',
            'commission' => 'Commission', 'avg_deal_value' => 'Avg Deal Value')),
        'agents' => array('Agent Leaderboard', array(
            'rank' => 'Rank', 'agent_name' => 'Agent', 'leads' => 'Leads', 'leads_won' => 'Leads Won',
            'lead_conversion' => 'Lead Conversion %', 'deals_won' => 'Deals Won', 'revenue' => 'Revenue',
            'commission' => 'Commission', 'open_deals' => 'Open Deals', 'open_value' => 'Open Pipeline',
            'tasks_completed' => 'Tasks Completed')),
        'sources' => array('Lead Source Effectiveness', array(
            'source' => 'Source', 'leads' => 'Leads', 'share' => '% of Leads', 'won' => 'Won', 'lost' => 'Lost',
            'open' => 'Open', 'conversion_rate' => 'Conversion %', 'win_rate' => 'Win Rate %')),
        'grades' => array('Lead Grade Distribution', array(
            'grade' => 'Grade', 'leads' => 'Leads', 'share' => '% of Leads', 'avg_score' => 'Avg Score',
            'min_score' => 'Min Score', 'max_score' => 'Max Score', 'won' => 'Won', 'conversion_rate' => 'Conversion %')),
        'properties' => array('Property Listings by Type', array(
            'label' => 'Type', 'listings' => 'Listings', 'avg_price' => 'Avg Price', 'total_value' => 'Total Value',
            'available' => 'Available', 'pending' => 'Pending', 'sold' => 'Sold', 'rented' => 'Rented',
            'off_market' => 'Off Market'))
    );

    public function __construct() {
        require_once APP_PATH . '/helpers/ReportService.php';
        $this->reportService = new ReportService();
    }

    /**
     * Overview dashboard
     */
    public function index() {
        list($tenantId, $start, $end) = $this->prepare();
        $this->render('reports/index', 'Reports & Analytics', 'index', $start, $end, array(
            'overview' => $this->reportService->getOverview($tenantId, $start, $end)
        ));
    }

    /**
     * Lead analytics: funnel, sources, grades
     */
    public function leads() {
        list($tenantId, $start, $end) = $this->prepare();
        $this->render('reports/leads', 'Lead Analytics', 'leads', $start, $end, array(
            'funnel' => $this->reportService->getSalesFunnel($tenantId, $start, $end),
            'sources' => $this->reportService->getLeadSourceEffectiveness($tenantId, $start, $end),
            'grades' => $this->reportService->getLeadGradeDistribution($tenantId, $start, $end)
        ));
    }

    /**
     * Agent leaderboard
     */
    public function agents() {
        list($tenantId, $start, $end) = $this->prepare();
        $this->render('reports/agents', 'Agent Performance', 'agents', $start, $end, array(
            'agents' => $this->reportService->getAgentLeaderboard($tenantId, $start, $end)
        ));
    }

    /**
     * Revenue by month + deal pipeline
     */
    public function revenue() {
        list($tenantId, $start, $end) = $this->prepare();
        $this->render('reports/revenue', 'Revenue & Pipeline', 'revenue', $start, $end, array(
            'revenue' => $this->reportService->getRevenueByMonth($tenantId, $start, $end),
            'pipeline' => $this->reportService->getDealPipeline($tenantId, $start, $end)
        ));
    }

    /**
     * Property analytics
     */
    public function properties() {
        list($tenantId, $start, $end) = $this->prepare();
        $this->render('reports/properties', 'Property Analytics', 'properties', $start, $end, array(
            'distribution' => $this->reportService->getPropertyTypeDistribution($tenantId, $start, $end),
            'cities' => $this->reportService->getPropertyCityDistribution($tenantId, $start, $end)
        ));
    }

    /**
     * JSON data for charts: /reports/chartData/{type}?start_date=&end_date=
     * @param string $type funnel|pipeline|revenue|agents|sources|grades|properties|tasks
     */
    public function chartData($type = '') {
        list($tenantId, $start, $end) = $this->prepare();
        $data = $this->reportData($type, $tenantId, $start, $end, true);
        if ($data === null) {
            $this->jsonResponse(array('success' => false, 'error' => 'Invalid report type'), 400);
        }
        $this->jsonResponse(array('success' => true, 'start_date' => $start, 'end_date' => $end, 'data' => $data));
    }

    /**
     * CSV export of a report table: /reports/export/{type}?start_date=&end_date=
     * @param string $type See self::$exports
     */
    public function export($type = '') {
        $this->requirePermission('reports.export');
        list($tenantId, $start, $end) = $this->prepare();

        if (!is_string($type) || !isset(self::$exports[$type])) {
            $this->setFlash('error', 'Unknown report type.');
            $this->redirect('reports/index');
        }

        $rows = $this->reportData($type, $tenantId, $start, $end, false);
        list($title, $columns) = self::$exports[$type];

        require_once APP_PATH . '/helpers/ExportService.php';
        $exportService = new ExportService();
        logActivity($this->getUserId(), $tenantId, 'exported_report', 'report', 0);
        $exportService->exportToCSV($rows, $columns, 'report_' . $type . '_' . $start . '_to_' . $end . '.csv');
    }

    // ------------------------------------------------------------------

    /**
     * Rows for a report type
     * @param string $type
     * @param bool $full Return the full structure (charts) instead of table rows (CSV)
     * @return array|null
     */
    private function reportData($type, $tenantId, $start, $end, $full) {
        switch ($type) {
            case 'funnel':
                $d = $this->reportService->getSalesFunnel($tenantId, $start, $end);
                return $full ? $d : $d['stages'];
            case 'pipeline':
                $d = $this->reportService->getDealPipeline($tenantId, $start, $end);
                return $full ? $d : $d['stages'];
            case 'revenue':
                $d = $this->reportService->getRevenueByMonth($tenantId, $start, $end);
                return $full ? $d : $d['months'];
            case 'agents':
                return $this->reportService->getAgentLeaderboard($tenantId, $start, $end);
            case 'sources':
                return $this->reportService->getLeadSourceEffectiveness($tenantId, $start, $end);
            case 'grades':
                $d = $this->reportService->getLeadGradeDistribution($tenantId, $start, $end);
                if ($full) {
                    return $d;
                }
                $rows = $d['grades'];
                $rows[] = array('grade' => 'Not scored', 'leads' => $d['unscored'],
                    'share' => $d['total'] > 0 ? round($d['unscored'] / $d['total'] * 100, 1) : 0);
                return $rows;
            case 'properties':
                return $this->reportService->getPropertyTypeDistribution($tenantId, $start, $end);
            case 'tasks':
                return $full ? $this->reportService->getTaskMetrics($tenantId, $start, $end) : null;
        }
        return null;
    }

    /**
     * Permission, tenant and date range for every action
     * @return array array($tenantId, $startDate, $endDate)
     */
    private function prepare() {
        $this->requirePermission('reports.view');
        list($start, $end) = ReportService::normalizeDateRange(
            isset($_GET['start_date']) ? $_GET['start_date'] : null,
            isset($_GET['end_date']) ? $_GET['end_date'] : null
        );
        return array((int)$this->getTenantId(), $start, $end);
    }

    /**
     * Render a report page with the shared variables
     */
    private function render($view, $title, $active, $start, $end, $data) {
        $data['title'] = $title;
        $data['reportTitle'] = $title;
        $data['activeReport'] = $active;
        $data['startDate'] = $start;
        $data['endDate'] = $end;
        $data['canExport'] = can('reports.export');
        $data['noTenant'] = !$this->getTenantId();
        $this->view($view, $data);
    }
}

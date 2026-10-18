<?php
// FILE: /app/controllers/ExportController.php

/**
 * SplashEstate CRM - Export Controller
 * CSV downloads and printable ("Save as PDF") reports for list pages.
 * Exports honour the same query-string filters as the list pages and are
 * always scoped to the current tenant.
 *
 * Permissions: leads -> leads.export; other modules -> {module}.view AND reports.export
 * (there are no per-module export permissions for them).
 */

class ExportController extends Controller {

    private $exportService;

    public function __construct() {
        require_once APP_PATH . '/helpers/ExportService.php';
        $this->exportService = new ExportService();
    }

    /** Export leads to CSV */
    public function leadsCSV() {
        $this->csv('leads');
    }

    /** Printable leads report (save as PDF from the browser) */
    public function leadsPDF() {
        $this->printable('leads', 'Leads Report', 'landscape');
    }

    /** Export clients to CSV */
    public function clientsCSV() {
        $this->csv('clients');
    }

    /** Export properties to CSV */
    public function propertiesCSV() {
        $this->csv('properties');
    }

    /** Printable properties report */
    public function propertiesPDF() {
        $this->printable('properties', 'Properties Report', 'landscape');
    }

    /** Export deals to CSV */
    public function dealsCSV() {
        $this->csv('deals');
    }

    /** Export tasks to CSV */
    public function tasksCSV() {
        $this->csv('tasks');
    }

    /**
     * Check permissions for exporting a module
     * @param string $module
     */
    private function authorize($module) {
        $this->requireLogin();
        if ($module === 'leads') {
            $this->requirePermission('leads.export');
        } else {
            $this->requirePermission($module . '.view');
            $this->requirePermission('reports.export');
        }
        if (!$this->getTenantId()) {
            $this->setFlash('error', 'Exports are only available inside a tenant account.');
            $this->redirect('dashboard/index');
        }
    }

    /**
     * @param string $module
     */
    private function csv($module) {
        $this->authorize($module);
        $tenantId = (int)$this->getTenantId();

        $rows = $this->exportService->fetchRows($module, $tenantId, $_GET);
        logActivity($this->getUserId(), $tenantId, 'exported_csv', $module, 0);

        $this->exportService->exportToCSV(
            $rows,
            ExportService::columns($module),
            $module . '_' . date('Y-m-d_His') . '.csv'
        );
    }

    /**
     * @param string $module
     * @param string $title
     * @param string $orientation
     */
    private function printable($module, $title, $orientation) {
        $this->authorize($module);
        $tenantId = (int)$this->getTenantId();

        $rows = $this->exportService->fetchRows($module, $tenantId, $_GET);
        logActivity($this->getUserId(), $tenantId, 'exported_print', $module, 0);

        $headers = ExportService::columns($module);
        // Keep printable reports readable: drop long free-text columns
        unset($headers['description'], $headers['tags'], $headers['address']);

        foreach ($rows as &$row) {
            foreach (array('created_at', 'due_date', 'completed_at') as $dateKey) {
                if (!empty($row[$dateKey])) {
                    $row[$dateKey] = date('M j, Y', strtotime($row[$dateKey]));
                }
            }
            foreach (array('price', 'budget_min', 'budget_max', 'deal_value', 'commission') as $moneyKey) {
                if (isset($row[$moneyKey]) && $row[$moneyKey] !== null && $row[$moneyKey] !== '') {
                    $row[$moneyKey] = '$' . number_format((float)$row[$moneyKey], 0);
                }
            }
            foreach (array('status', 'interest_type', 'property_type', 'listing_type') as $labelKey) {
                if (!empty($row[$labelKey])) {
                    $row[$labelKey] = ucwords(str_replace('_', ' ', $row[$labelKey]));
                }
            }
        }
        unset($row);

        $this->exportService->renderPrintable(
            $title,
            $headers,
            $rows,
            array('Filters' => ExportService::describeFilters($_GET, $module)),
            $orientation
        );
    }
}

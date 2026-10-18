<?php
// FILE: /app/helpers/ExportService.php

/**
 * SplashEstate CRM - Export Service
 * Tenant-scoped data fetching for exports, CSV output (with spreadsheet
 * formula-injection protection) and printable HTML reports.
 *
 * Note: there is no PDF library in this project. "PDF" exports are rendered
 * as a clean print-optimised HTML page that the user saves as PDF through the
 * browser's Print dialog.
 */

require_once APP_PATH . '/helpers/SearchHelper.php';

class ExportService {

    /** Hard cap on exported rows per request */
    const MAX_ROWS = 10000;

    /** @var PDO */
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // CSV output
    // ------------------------------------------------------------------

    /**
     * Neutralise a cell value for spreadsheet apps: text starting with
     * = + - @ (or a tab / carriage return) is prefixed with a single quote so
     * Excel/Sheets/LibreOffice treat it as text rather than a formula.
     * Plain numbers (e.g. -12.5) are left untouched.
     * @param mixed $value
     * @return string
     */
    public static function sanitizeCell($value) {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (!is_scalar($value)) {
            $value = json_encode($value);
        }
        $value = (string)$value;
        if ($value !== '' && !is_numeric($value) && strpos("=+-@\t\r", $value[0]) !== false) {
            $value = "'" . $value;
        }
        return $value;
    }

    /**
     * Format one CSV line (RFC 4180 quoting)
     * @param array $fields
     * @return string
     */
    public static function csvLine($fields) {
        $out = array();
        foreach ($fields as $field) {
            $field = self::sanitizeCell($field);
            if ($field === '' || preg_match('/[",\r\n;]|^\s|\s$/', $field)) {
                $field = '"' . str_replace('"', '""', $field) . '"';
            }
            $out[] = $field;
        }
        return implode(',', $out) . "\r\n";
    }

    /**
     * Make a safe download filename
     * @param string $filename
     * @param string $extension Forced extension (without dot)
     * @return string
     */
    public static function safeFilename($filename, $extension = 'csv') {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', (string)$filename);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        $base = trim($base, '._-');
        if ($base === '') {
            $base = 'export';
        }
        return substr($base, 0, 120) . '.' . $extension;
    }

    /**
     * Stream rows as a CSV download and exit
     * @param array $data Rows (associative arrays)
     * @param array $headers column key => header label
     * @param string $filename Download filename
     */
    public function exportToCSV($data, $headers, $filename = 'export.csv') {
        $filename = self::safeFilename($filename, 'csv');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // UTF-8 BOM so Excel detects the encoding
        echo "\xEF\xBB\xBF";
        echo self::csvLine(array_values($headers));

        foreach ($data as $row) {
            $line = array();
            foreach ($headers as $key => $label) {
                $line[] = isset($row[$key]) ? $row[$key] : '';
            }
            echo self::csvLine($line);
        }
        exit;
    }

    // ------------------------------------------------------------------
    // Printable HTML ("Save as PDF")
    // ------------------------------------------------------------------

    /**
     * Output a print-ready HTML page and exit
     * @param string $title Report title (plain text)
     * @param array $headers column key => label
     * @param array $rows Rows
     * @param array $meta Extra lines shown under the title (label => value, plain text)
     * @param string $orientation portrait|landscape
     */
    public function renderPrintable($title, $headers, $rows, $meta = array(), $orientation = 'portrait') {
        $orientation = $orientation === 'landscape' ? 'landscape' : 'portrait';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        $h = function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>' . $h($title) . ' - SplashEstate CRM</title>';
        echo '<style>
            @page { size: A4 ' . $orientation . '; margin: 14mm; }
            * { box-sizing: border-box; }
            body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; font-size: 12px; color: #2c3e50; margin: 24px; }
            header { border-bottom: 3px solid #3498db; margin-bottom: 16px; padding-bottom: 8px; }
            h1 { font-size: 20px; margin: 0 0 4px; }
            .meta { color: #7f8c8d; margin: 2px 0; }
            .toolbar { margin-bottom: 16px; display: flex; gap: 8px; }
            .toolbar button, .toolbar a { font: inherit; padding: 6px 14px; border: 1px solid #3498db; background: #3498db; color: #fff; border-radius: 4px; cursor: pointer; text-decoration: none; }
            .toolbar a { background: #fff; color: #3498db; }
            .hint { color: #7f8c8d; font-size: 11px; align-self: center; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #dfe4ea; padding: 5px 7px; text-align: left; vertical-align: top; }
            th { background: #2c3e50; color: #fff; font-weight: 600; }
            tbody tr:nth-child(even) { background: #f5f7fa; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            .empty { padding: 24px; text-align: center; color: #7f8c8d; }
            footer { margin-top: 16px; color: #95a5a6; font-size: 10px; text-align: center; }
            @media print {
                body { margin: 0; }
                .toolbar { display: none; }
                th { background: #2c3e50 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style></head><body>';

        echo '<div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button>';
        echo '<a href="javascript:history.back()">Back</a>';
        echo '<span class="hint">Choose &ldquo;Save as PDF&rdquo; as the printer to get a PDF file.</span></div>';

        echo '<header><h1>' . $h($title) . '</h1>';
        echo '<p class="meta">Generated ' . $h(date('F j, Y g:i A')) . ' &middot; ' . count($rows) . ' record(s)</p>';
        foreach ($meta as $label => $value) {
            echo '<p class="meta">' . $h($label) . ': ' . $h($value) . '</p>';
        }
        echo '</header>';

        if (empty($rows)) {
            echo '<div class="empty">No records match the selected filters.</div>';
        } else {
            echo '<table><thead><tr>';
            foreach ($headers as $label) {
                echo '<th>' . $h($label) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($headers as $key => $label) {
                    echo '<td>' . $h(isset($row[$key]) ? $row[$key] : '') . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<footer>&copy; ' . date('Y') . ' SplashEstate CRM &middot; Confidential</footer>';
        echo '</body></html>';
        exit;
    }

    // ------------------------------------------------------------------
    // Column definitions
    // ------------------------------------------------------------------

    /**
     * CSV columns per module
     * @param string $module
     * @return array key => label
     */
    public static function columns($module) {
        switch ($module) {
            case 'leads':
                return array(
                    'id' => 'ID', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'email' => 'Email',
                    'phone' => 'Phone', 'source' => 'Source', 'status' => 'Status', 'interest_type' => 'Interest Type',
                    'budget_min' => 'Budget Min', 'budget_max' => 'Budget Max', 'tags' => 'Tags',
                    'score' => 'Score', 'grade' => 'Grade', 'assigned_to_name' => 'Assigned To', 'created_at' => 'Created'
                );
            case 'clients':
                return array(
                    'id' => 'ID', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'email' => 'Email',
                    'phone' => 'Phone', 'address' => 'Address', 'city' => 'City', 'state' => 'State', 'zip' => 'ZIP',
                    'client_type' => 'Type', 'status' => 'Status', 'created_at' => 'Created'
                );
            case 'properties':
                return array(
                    'id' => 'ID', 'title' => 'Title', 'property_type' => 'Type', 'sub_type' => 'Sub-type',
                    'listing_type' => 'Listing Type', 'price' => 'Price', 'bedrooms' => 'Bedrooms',
                    'bathrooms' => 'Bathrooms', 'square_feet' => 'Sq Ft', 'address' => 'Address', 'city' => 'City',
                    'state' => 'State', 'zip' => 'ZIP', 'status' => 'Status', 'agent_name' => 'Listing Agent',
                    'created_at' => 'Created'
                );
            case 'deals':
                return array(
                    'id' => 'ID', 'title' => 'Title', 'client_name' => 'Client', 'property_title' => 'Property',
                    'deal_value' => 'Value', 'commission' => 'Commission', 'stage' => 'Stage',
                    'probability' => 'Probability %', 'expected_close_date' => 'Expected Close',
                    'actual_close_date' => 'Closed', 'agent_name' => 'Agent', 'created_at' => 'Created'
                );
            case 'tasks':
                return array(
                    'id' => 'ID', 'title' => 'Title', 'description' => 'Description', 'related_to_type' => 'Related To',
                    'related_to_id' => 'Related ID', 'assigned_to_name' => 'Assigned To', 'due_date' => 'Due Date',
                    'priority' => 'Priority', 'status' => 'Status', 'completed_at' => 'Completed', 'created_at' => 'Created'
                );
        }
        return array('id' => 'ID');
    }

    // ------------------------------------------------------------------
    // Data fetching (tenant-scoped, filters whitelisted)
    // ------------------------------------------------------------------

    /**
     * Filter definitions per module (keys = query-string names)
     * @param string $module
     * @return array
     */
    private static function filterDefinition($module) {
        switch ($module) {
            case 'leads':
                return SearchHelper::leadFilterDefinition();
            case 'clients':
                return array(
                    'client_type' => array('type' => 'enum', 'column' => 'c.client_type', 'values' => array('buyer', 'seller', 'landlord', 'tenant')),
                    'status' => array('type' => 'enum', 'column' => 'c.status', 'values' => array('active', 'inactive')),
                    'city' => array('type' => 'equals', 'column' => 'c.city'),
                    'search' => array('type' => 'search', 'columns' => array('c.first_name', 'c.last_name', 'c.email', 'c.phone', 'c.city')),
                    'created' => array('type' => 'daterange', 'column' => 'c.created_at', 'from' => 'created_from', 'to' => 'created_to')
                );
            case 'properties':
                return array(
                    'property_type' => array('type' => 'enum', 'column' => 'p.property_type', 'values' => array('residential', 'commercial', 'land')),
                    'listing_type' => array('type' => 'enum', 'column' => 'p.listing_type', 'values' => array('sale', 'rent', 'lease')),
                    'status' => array('type' => 'enum', 'column' => 'p.status', 'values' => array('available', 'pending', 'sold', 'rented', 'off_market')),
                    'city' => array('type' => 'equals', 'column' => 'p.city'),
                    'bedrooms' => array('type' => 'int', 'column' => 'p.bedrooms'),
                    'search' => array('type' => 'search', 'columns' => array('p.title', 'p.address', 'p.city', 'p.zip')),
                    'price' => array('type' => 'range', 'column' => 'p.price', 'min' => 'price_min', 'max' => 'price_max'),
                    'created' => array('type' => 'daterange', 'column' => 'p.created_at', 'from' => 'created_from', 'to' => 'created_to')
                );
            case 'deals':
                return array(
                    'stage' => array('type' => 'enum', 'column' => 'd.stage',
                        'values' => array('lead', 'qualified', 'viewing', 'offer', 'negotiation', 'contract', 'closed_won', 'closed_lost')),
                    'assigned_to' => array('type' => 'int', 'column' => 'd.assigned_to'),
                    'search' => array('type' => 'search', 'columns' => array('d.title', 'd.notes')),
                    'value' => array('type' => 'range', 'column' => 'd.deal_value', 'min' => 'value_min', 'max' => 'value_max'),
                    'created' => array('type' => 'daterange', 'column' => 'd.created_at', 'from' => 'created_from', 'to' => 'created_to')
                );
            case 'tasks':
                return array(
                    'status' => array('type' => 'enum', 'column' => 't.status', 'values' => array('pending', 'in_progress', 'completed', 'cancelled')),
                    'priority' => array('type' => 'enum', 'column' => 't.priority', 'values' => array('low', 'medium', 'high', 'urgent')),
                    'assigned_to' => array('type' => 'int', 'column' => 't.assigned_to'),
                    'related_to_type' => array('type' => 'enum', 'column' => 't.related_to_type', 'values' => array('lead', 'client', 'property', 'deal')),
                    'search' => array('type' => 'search', 'columns' => array('t.title', 't.description')),
                    'due' => array('type' => 'daterange', 'column' => 't.due_date', 'from' => 'due_from', 'to' => 'due_to')
                );
        }
        return array();
    }

    /**
     * Base SELECT, alias and sort whitelist per module
     * @param string $module
     * @return array array(baseSql, tenantColumn, sortOptions, defaultSort)
     */
    private static function moduleQuery($module) {
        switch ($module) {
            case 'leads':
                return array(
                    "SELECT l.*, CONCAT(u.first_name, ' ', u.last_name) AS assigned_to_name, ls.score, ls.grade
                     FROM leads l
                     LEFT JOIN users u ON u.id = l.assigned_to AND u.tenant_id = l.tenant_id
                     LEFT JOIN lead_scores ls ON ls.lead_id = l.id",
                    'l.tenant_id', array_keys(SearchHelper::leadSortOptions()), 'l.created_at'
                );
            case 'clients':
                return array(
                    "SELECT c.* FROM clients c",
                    'c.tenant_id', array('c.created_at', 'c.first_name', 'c.last_name', 'c.city', 'c.status'), 'c.created_at'
                );
            case 'properties':
                return array(
                    "SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) AS agent_name
                     FROM properties p
                     LEFT JOIN users u ON u.id = p.listing_agent_id AND u.tenant_id = p.tenant_id",
                    'p.tenant_id', array('p.created_at', 'p.price', 'p.title', 'p.city', 'p.status'), 'p.created_at'
                );
            case 'deals':
                return array(
                    "SELECT d.*, CONCAT(c.first_name, ' ', c.last_name) AS client_name, p.title AS property_title,
                            CONCAT(u.first_name, ' ', u.last_name) AS agent_name
                     FROM deals d
                     LEFT JOIN clients c ON c.id = d.client_id AND c.tenant_id = d.tenant_id
                     LEFT JOIN properties p ON p.id = d.property_id AND p.tenant_id = d.tenant_id
                     LEFT JOIN users u ON u.id = d.assigned_to AND u.tenant_id = d.tenant_id",
                    'd.tenant_id', array('d.created_at', 'd.deal_value', 'd.title', 'd.stage', 'd.expected_close_date'), 'd.created_at'
                );
            case 'tasks':
                return array(
                    "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS assigned_to_name
                     FROM tasks t
                     LEFT JOIN users u ON u.id = t.assigned_to AND u.tenant_id = t.tenant_id",
                    't.tenant_id', array('t.due_date', 't.created_at', 't.priority', 't.status', 't.title'), 't.due_date'
                );
        }
        throw new InvalidArgumentException('Unknown export module');
    }

    /**
     * Fetch rows for a module export using the same filter keys as the list pages
     * @param string $module leads|clients|properties|deals|tasks
     * @param int $tenantId Tenant ID
     * @param array $filters Raw filters (e.g. $_GET)
     * @param array|null $ids Restrict to these IDs (bulk export of a selection)
     * @return array Rows
     */
    public function fetchRows($module, $tenantId, $filters = array(), $ids = null) {
        list($baseSql, $tenantColumn, $sortOptions, $defaultSort) = self::moduleQuery($module);

        $search = new SearchHelper();
        $search->where($tenantColumn, (int)$tenantId);
        $search->applyFilters(is_array($filters) ? $filters : array(), self::filterDefinition($module));

        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
            if (empty($ids)) {
                return array();
            }
            $alias = substr($tenantColumn, 0, strpos($tenantColumn, '.'));
            $search->where($alias . '.id', $ids, 'IN');
        }

        $sort = isset($filters['sort']) && is_string($filters['sort']) ? $filters['sort'] : null;
        $direction = isset($filters['direction']) && is_string($filters['direction']) ? $filters['direction'] : 'DESC';
        $search->orderBy($sort, $direction, $sortOptions, $defaultSort);
        $search->limit(self::MAX_ROWS, 0);

        return $search->execute($baseSql);
    }

    /**
     * Human readable summary of the active filters (for printable reports)
     * @param array $filters
     * @param string $module
     * @return string
     */
    public static function describeFilters($filters, $module) {
        $allowed = SearchHelper::allowedFilterKeys($module);
        $parts = array();
        foreach ($allowed as $key) {
            if (in_array($key, array('sort', 'direction'), true)) {
                continue;
            }
            if (isset($filters[$key]) && is_scalar($filters[$key]) && trim((string)$filters[$key]) !== '') {
                $parts[] = str_replace('_', ' ', $key) . ' = ' . trim((string)$filters[$key]);
            }
        }
        return empty($parts) ? 'None' : implode(', ', $parts);
    }
}

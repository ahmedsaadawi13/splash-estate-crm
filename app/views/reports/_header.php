<?php
// FILE: /app/views/reports/_header.php
/**
 * Shared report page header: title, report navigation, date-range filter and
 * a small Chart.js loader. Expects $reportTitle, $activeReport, $startDate,
 * $endDate, $canExport, $noTenant (set by ReportsController::render()).
 *
 * Defines $reportQuery (start/end query string) and $reportExportUrl($type).
 */
require APP_PATH . '/views/layouts/header.php';

$reportQuery = http_build_query(array('start_date' => $startDate, 'end_date' => $endDate));
$reportExportUrl = function ($type) use ($reportQuery) {
    return url('reports/export/' . rawurlencode($type)) . '?' . $reportQuery;
};
// JSON for inline <script> blocks (safe against "</script>" in data)
$reportJson = function ($value) {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
};
$reportNav = array(
    'index' => 'Overview',
    'leads' => 'Leads',
    'agents' => 'Agents',
    'revenue' => 'Revenue & Pipeline',
    'properties' => 'Properties'
);
$reportPath = $activeReport === 'index' ? 'reports/index' : 'reports/' . $activeReport;
$today = date('Y-m-d');
$reportPresets = array(
    'Last 30 days' => array(date('Y-m-d', strtotime('-30 days')), $today),
    'Last 90 days' => array(date('Y-m-d', strtotime('-90 days')), $today),
    'This year' => array(date('Y-01-01'), $today),
    'Last 12 months' => array(date('Y-m-d', strtotime('-12 months')), $today)
);
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('reports/index')); ?>">Reports</a><?php if ($activeReport !== 'index'): ?> / <?php echo e($reportTitle); ?><?php endif; ?></div>
        <h1><?php echo e($reportTitle); ?></h1>
    </div>
</div>

<nav class="report-nav" aria-label="Reports">
    <?php foreach ($reportNav as $key => $label): ?>
        <a href="<?php echo e(url($key === 'index' ? 'reports/index' : 'reports/' . $key)); ?>?<?php echo e($reportQuery); ?>"
           class="btn btn-sm <?php echo $key === $activeReport ? 'btn-primary' : 'btn-outline'; ?>"
           <?php echo $key === $activeReport ? 'aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
    <?php endforeach; ?>
</nav>

<div class="card report-filter">
    <div class="card-body">
        <form method="GET" action="<?php echo e(url($reportPath)); ?>" class="d-flex flex-wrap align-center gap-2">
            <label for="report_start" class="small"><strong>From</strong></label>
            <input type="date" id="report_start" name="start_date" class="form-control" value="<?php echo e($startDate); ?>" max="<?php echo e($today); ?>" required>
            <label for="report_end" class="small"><strong>To</strong></label>
            <input type="date" id="report_end" name="end_date" class="form-control" value="<?php echo e($endDate); ?>" required>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            <span class="text-muted small">Quick ranges:</span>
            <?php foreach ($reportPresets as $label => $range): ?>
                <a class="btn btn-link btn-sm" href="<?php echo e(url($reportPath)); ?>?<?php echo e(http_build_query(array('start_date' => $range[0], 'end_date' => $range[1]))); ?>"><?php echo e($label); ?></a>
            <?php endforeach; ?>
        </form>
        <p class="text-muted small mt-1 mb-0">
            Showing <?php echo e(date('M j, Y', strtotime($startDate))); ?> &ndash; <?php echo e(date('M j, Y', strtotime($endDate))); ?>.
            Lead, deal and property figures cover records created in this period; revenue and deals won use the deal close date.
        </p>
    </div>
</div>

<?php if (!empty($noTenant)): ?>
    <div class="alert alert-info">Reports are tenant-specific. Sign in with a tenant account to see data.</div>
<?php endif; ?>

<style>
.report-nav { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
.report-filter .form-control { width: auto; }
.report-chart { position: relative; height: 280px; }
.report-chart.is-short { height: 220px; }
.report-chart-fallback { color: #7f8c8d; font-size: 0.9rem; padding: 1rem 0; }
.report-table td.num, .report-table th.num { text-align: right; white-space: nowrap; }
.report-table tfoot td { font-weight: 600; border-top: 2px solid #dfe4ea; }
.report-bar { display: inline-block; height: 8px; background: #2a78d6; border-radius: 0 4px 4px 0; vertical-align: middle; min-width: 2px; }
.mb-0 { margin-bottom: 0; }
@media print {
    .navbar, .footer, .report-nav, .report-filter form, .header-actions, .btn { display: none !important; }
    .card { box-shadow: none; border: 1px solid #ddd; break-inside: avoid; }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/**
 * Render a Chart.js chart if the library loaded; otherwise show a note.
 * The data is always also available in the table next to each chart.
 */
function reportChart(canvasId, config) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) { return null; }
    if (typeof Chart === 'undefined') {
        var note = document.createElement('div');
        note.className = 'report-chart-fallback';
        note.textContent = 'Chart unavailable (charting library could not be loaded). See the table for the data.';
        canvas.parentNode.replaceChild(note, canvas);
        return null;
    }
    config.options = config.options || {};
    config.options.responsive = true;
    config.options.maintainAspectRatio = false;
    return new Chart(canvas.getContext('2d'), config);
}
var REPORT_COLORS = { primary: '#2a78d6', secondary: '#eb6834', muted: '#c3c2b7', grid: '#ecf0f1' };
function reportMoney(value) {
    return '$' + Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
}
</script>

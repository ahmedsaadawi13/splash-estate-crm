<?php
// FILE: /app/views/reports/index.php
// Variables: $overview (ReportService::getOverview), $startDate, $endDate, $canExport
require APP_PATH . '/views/reports/_header.php';

$kpis = $overview['kpis'];
$funnel = $overview['funnel'];
$pipeline = $overview['pipeline'];
$revenue = $overview['revenue'];
$grades = $overview['grades'];
?>

<div class="stats-grid">
    <div class="stat-card">
        <h3>New Leads</h3>
        <div class="stat-number"><?php echo (int)$kpis['new_leads']; ?></div>
        <div class="stat-label"><?php echo (int)$kpis['leads_won']; ?> won &middot; <?php echo e(number_format($kpis['conversion_rate'], 1)); ?>% conversion</div>
    </div>
    <div class="stat-card">
        <h3>Revenue (won deals)</h3>
        <div class="stat-number"><?php echo e(View::formatCurrency($kpis['revenue'])); ?></div>
        <div class="stat-label"><?php echo (int)$kpis['deals_won']; ?> deal(s) &middot; <?php echo e(View::formatCurrency($kpis['commission'])); ?> commission</div>
    </div>
    <div class="stat-card">
        <h3>Open Pipeline</h3>
        <div class="stat-number"><?php echo e(View::formatCurrency($kpis['open_pipeline'])); ?></div>
        <div class="stat-label"><?php echo e(View::formatCurrency($kpis['weighted_pipeline'])); ?> weighted by probability</div>
    </div>
    <div class="stat-card">
        <h3>Tasks Completed</h3>
        <div class="stat-number"><?php echo (int)$kpis['tasks_completed']; ?></div>
        <div class="stat-label"><?php echo (int)$kpis['tasks_overdue']; ?> overdue</div>
    </div>
</div>

<div class="grid-2 mt-2">
    <div class="card">
        <div class="card-header">
            <h3>Sales Funnel</h3>
            <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('funnel')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($funnel['total'] === 0): ?>
                <div class="empty-state"><p>No leads were created in this period.</p></div>
            <?php else: ?>
                <div class="report-chart"><canvas id="funnelChart" aria-label="Leads by status" role="img"></canvas></div>
                <p class="small text-muted mt-1">Win rate (won / closed): <strong><?php echo e(number_format($funnel['win_rate'], 1)); ?>%</strong> &middot; open leads: <?php echo (int)$funnel['open']; ?></p>
            <?php endif; ?>
        </div>
        <div class="card-footer text-right"><a href="<?php echo e(url('reports/leads')); ?>?<?php echo e($reportQuery); ?>" class="btn btn-link btn-sm">Lead analytics &rarr;</a></div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Deal Pipeline by Stage</h3>
            <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('pipeline')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($pipeline['totals']['deal_count'] === 0): ?>
                <div class="empty-state"><p>No deals were created in this period.</p></div>
            <?php else: ?>
                <div class="report-chart"><canvas id="pipelineChart" aria-label="Deal value by stage" role="img"></canvas></div>
            <?php endif; ?>
        </div>
        <div class="card-footer text-right"><a href="<?php echo e(url('reports/revenue')); ?>?<?php echo e($reportQuery); ?>" class="btn btn-link btn-sm">Revenue &amp; pipeline &rarr;</a></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Revenue by Month</h3>
        <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('revenue')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($revenue['totals']['deal_count'] === 0): ?>
            <div class="empty-state"><p>No deals were won in this period.</p></div>
        <?php else: ?>
            <div class="report-chart is-short"><canvas id="revenueChart" aria-label="Revenue by month" role="img"></canvas></div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Top Agents</h3>
        <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('agents')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
    </div>
    <div class="card-body table-responsive">
        <?php if (empty($overview['agents'])): ?>
            <div class="empty-state"><p>No agents found.</p></div>
        <?php else: ?>
        <table class="table report-table">
            <thead>
                <tr>
                    <th>#</th><th>Agent</th><th class="num">Leads</th><th class="num">Won</th>
                    <th class="num">Deals Won</th><th class="num">Revenue</th><th class="num">Tasks Done</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overview['agents'] as $agent): ?>
                <tr>
                    <td><?php echo (int)$agent['rank']; ?></td>
                    <td><?php echo e($agent['agent_name']); ?></td>
                    <td class="num"><?php echo (int)$agent['leads']; ?></td>
                    <td class="num"><?php echo (int)$agent['leads_won']; ?></td>
                    <td class="num"><?php echo (int)$agent['deals_won']; ?></td>
                    <td class="num"><?php echo e(View::formatCurrency($agent['revenue'])); ?></td>
                    <td class="num"><?php echo (int)$agent['tasks_completed']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="card-footer text-right"><a href="<?php echo e(url('reports/agents')); ?>?<?php echo e($reportQuery); ?>" class="btn btn-link btn-sm">Full leaderboard &rarr;</a></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h3>Lead Sources</h3>
            <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('sources')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
        </div>
        <div class="card-body table-responsive">
            <?php if (empty($overview['sources'])): ?>
                <div class="empty-state"><p>No leads in this period.</p></div>
            <?php else: ?>
            <table class="table report-table">
                <thead><tr><th>Source</th><th class="num">Leads</th><th class="num">Won</th><th class="num">Conversion</th></tr></thead>
                <tbody>
                    <?php foreach ($overview['sources'] as $source): ?>
                    <tr>
                        <td><?php echo e($source['source']); ?></td>
                        <td class="num"><?php echo (int)$source['leads']; ?></td>
                        <td class="num"><?php echo (int)$source['won']; ?></td>
                        <td class="num"><?php echo e(number_format($source['conversion_rate'], 1)); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Lead Grades</h3>
            <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('grades')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
        </div>
        <div class="card-body table-responsive">
            <?php if ($grades['total'] === 0): ?>
                <div class="empty-state"><p>No leads in this period.</p></div>
            <?php else: ?>
            <table class="table report-table">
                <thead><tr><th>Grade</th><th class="num">Leads</th><th class="num">Share</th><th class="num">Avg Score</th></tr></thead>
                <tbody>
                    <?php foreach ($grades['grades'] as $grade): ?>
                    <tr>
                        <td><span class="grade grade-<?php echo e($grade['grade']); ?>"><?php echo e($grade['grade']); ?></span></td>
                        <td class="num"><?php echo (int)$grade['leads']; ?></td>
                        <td class="num"><?php echo e(number_format($grade['share'], 1)); ?>%</td>
                        <td class="num"><?php echo $grade['avg_score'] === null ? '&ndash;' : e(number_format($grade['avg_score'], 1)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td class="text-muted">Not scored</td>
                        <td class="num"><?php echo (int)$grades['unscored']; ?></td>
                        <td class="num"><?php echo e(number_format($grades['total'] > 0 ? $grades['unscored'] / $grades['total'] * 100 : 0, 1)); ?>%</td>
                        <td class="num">&ndash;</td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var funnel = <?php echo $reportJson($funnel['stages']); ?>;
    var pipeline = <?php echo $reportJson($pipeline['stages']); ?>;
    var revenue = <?php echo $reportJson($revenue['months']); ?>;

    reportChart('funnelChart', {
        type: 'bar',
        data: {
            labels: funnel.map(function (s) { return s.label; }),
            datasets: [{
                label: 'Leads',
                data: funnel.map(function (s) { return s.count; }),
                backgroundColor: funnel.map(function (s) { return s.status === 'lost' ? REPORT_COLORS.muted : REPORT_COLORS.primary; }),
                borderRadius: 4, maxBarThickness: 28
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: REPORT_COLORS.grid } }, y: { grid: { display: false } } }
        }
    });

    var openStages = pipeline.filter(function (s) { return s.stage !== 'closed_lost'; });
    reportChart('pipelineChart', {
        type: 'bar',
        data: {
            labels: openStages.map(function (s) { return s.label; }),
            datasets: [{
                label: 'Total value',
                data: openStages.map(function (s) { return s.total_value; }),
                backgroundColor: REPORT_COLORS.primary, borderRadius: 4, maxBarThickness: 28
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function (ctx) {
                    var s = openStages[ctx.dataIndex];
                    return reportMoney(s.total_value) + ' (' + s.deal_count + ' deals, weighted ' + reportMoney(s.weighted_value) + ')';
                } } }
            },
            scales: { x: { beginAtZero: true, ticks: { callback: reportMoney }, grid: { color: REPORT_COLORS.grid } }, y: { grid: { display: false } } }
        }
    });

    reportChart('revenueChart', {
        type: 'bar',
        data: {
            labels: revenue.map(function (m) { return m.label; }),
            datasets: [{
                label: 'Revenue',
                data: revenue.map(function (m) { return m.revenue; }),
                backgroundColor: REPORT_COLORS.primary, borderRadius: 4, maxBarThickness: 40
            }]
        },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function (ctx) {
                    var m = revenue[ctx.dataIndex];
                    return reportMoney(m.revenue) + ' from ' + m.deal_count + ' deal(s), commission ' + reportMoney(m.commission);
                } } }
            },
            scales: { y: { beginAtZero: true, ticks: { callback: reportMoney }, grid: { color: REPORT_COLORS.grid } }, x: { grid: { display: false } } }
        }
    });
})();
</script>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

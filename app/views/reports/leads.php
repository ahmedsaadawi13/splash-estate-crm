<?php
// FILE: /app/views/reports/leads.php
// Variables: $funnel (getSalesFunnel), $sources (getLeadSourceEffectiveness), $grades (getLeadGradeDistribution)
require APP_PATH . '/views/reports/_header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <h3>Leads Created</h3>
        <div class="stat-number"><?php echo (int)$funnel['total']; ?></div>
        <div class="stat-label"><?php echo (int)$funnel['open']; ?> still open</div>
    </div>
    <div class="stat-card">
        <h3>Won</h3>
        <div class="stat-number"><?php echo (int)$funnel['won']; ?></div>
        <div class="stat-label"><?php echo e(number_format($funnel['conversion_rate'], 1)); ?>% of all leads</div>
    </div>
    <div class="stat-card">
        <h3>Lost</h3>
        <div class="stat-number"><?php echo (int)$funnel['lost']; ?></div>
        <div class="stat-label">Win rate <?php echo e(number_format($funnel['win_rate'], 1)); ?>% of closed leads</div>
    </div>
    <div class="stat-card">
        <h3>Average Lead Score</h3>
        <div class="stat-number"><?php echo $grades['avg_score'] === null ? '&ndash;' : e(number_format($grades['avg_score'], 1)); ?></div>
        <div class="stat-label"><?php echo (int)$grades['scored']; ?> scored, <?php echo (int)$grades['unscored']; ?> not scored</div>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header">
        <h3>Sales Funnel</h3>
        <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('funnel')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($funnel['total'] === 0): ?>
            <div class="empty-state"><p>No leads were created in this period.</p></div>
        <?php else: ?>
        <div class="layout-main-side">
            <div class="table-responsive">
                <table class="table report-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="num">Leads now</th>
                            <th class="num">% of leads</th>
                            <th class="num">Reached stage</th>
                            <th class="num">Conversion from previous stage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($funnel['stages'] as $stage): ?>
                        <tr>
                            <td><span class="badge <?php echo $stage['status'] === 'won' ? 'badge-success' : ($stage['status'] === 'lost' ? 'badge-danger' : 'badge-primary'); ?>"><?php echo e($stage['label']); ?></span></td>
                            <td class="num"><?php echo (int)$stage['count']; ?></td>
                            <td class="num"><?php echo e(number_format($stage['percent_of_total'], 1)); ?>%</td>
                            <td class="num"><?php echo $stage['reached'] === null ? '&ndash;' : (int)$stage['reached']; ?></td>
                            <td class="num"><?php echo $stage['stage_conversion'] === null ? '&ndash;' : e(number_format($stage['stage_conversion'], 1)) . '%'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td>Total</td><td class="num"><?php echo (int)$funnel['total']; ?></td><td class="num">100%</td><td></td><td></td></tr>
                    </tfoot>
                </table>
                <p class="small text-muted">
                    &ldquo;Reached stage&rdquo; counts leads currently at that stage or any later one (lost leads are excluded
                    because the stage at which they dropped out is not recorded).
                </p>
            </div>
            <div class="report-chart"><canvas id="funnelChart" role="img" aria-label="Leads that reached each stage"></canvas></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Lead Source Effectiveness</h3>
        <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('sources')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($sources)): ?>
            <div class="empty-state"><p>No leads were created in this period.</p></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table report-table">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th class="num">Leads</th>
                        <th class="num">% of leads</th>
                        <th class="num">Won</th>
                        <th class="num">Lost</th>
                        <th class="num">Open</th>
                        <th class="num">Conversion</th>
                        <th class="num">Win rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sources as $source): ?>
                    <tr>
                        <td><?php echo e($source['source']); ?></td>
                        <td class="num"><?php echo (int)$source['leads']; ?></td>
                        <td class="num"><span class="report-bar" style="width: <?php echo (int)round($source['share']); ?>px"></span> <?php echo e(number_format($source['share'], 1)); ?>%</td>
                        <td class="num"><?php echo (int)$source['won']; ?></td>
                        <td class="num"><?php echo (int)$source['lost']; ?></td>
                        <td class="num"><?php echo (int)$source['open']; ?></td>
                        <td class="num"><?php echo e(number_format($source['conversion_rate'], 1)); ?>%</td>
                        <td class="num"><?php echo e(number_format($source['win_rate'], 1)); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted">Conversion = won / all leads from the source. Win rate = won / (won + lost).</p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Lead Score &amp; Grade Distribution</h3>
        <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('grades')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($grades['total'] === 0): ?>
            <div class="empty-state"><p>No leads were created in this period.</p></div>
        <?php else: ?>
        <div class="layout-main-side">
            <div class="table-responsive">
                <table class="table report-table">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th class="num">Leads</th>
                            <th class="num">% of leads</th>
                            <th class="num">Avg score</th>
                            <th class="num">Score range</th>
                            <th class="num">Won</th>
                            <th class="num">Conversion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grades['grades'] as $grade): ?>
                        <tr>
                            <td><span class="grade grade-<?php echo e($grade['grade']); ?>"><?php echo e($grade['grade']); ?></span></td>
                            <td class="num"><?php echo (int)$grade['leads']; ?></td>
                            <td class="num"><?php echo e(number_format($grade['share'], 1)); ?>%</td>
                            <td class="num"><?php echo $grade['avg_score'] === null ? '&ndash;' : e(number_format($grade['avg_score'], 1)); ?></td>
                            <td class="num"><?php echo $grade['min_score'] === null ? '&ndash;' : (int)$grade['min_score'] . ' &ndash; ' . (int)$grade['max_score']; ?></td>
                            <td class="num"><?php echo (int)$grade['won']; ?></td>
                            <td class="num"><?php echo e(number_format($grade['conversion_rate'], 1)); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td class="text-muted">Not scored</td>
                            <td class="num"><?php echo (int)$grades['unscored']; ?></td>
                            <td class="num"><?php echo e(number_format($grades['total'] > 0 ? $grades['unscored'] / $grades['total'] * 100 : 0, 1)); ?>%</td>
                            <td class="num" colspan="4"></td>
                        </tr>
                    </tbody>
                </table>
                <?php if (can('scoring.manage')): ?>
                    <p class="small"><a href="<?php echo e(url('scoring/index')); ?>">Manage scoring rules</a></p>
                <?php endif; ?>
            </div>
            <div class="report-chart is-short"><canvas id="gradeChart" role="img" aria-label="Leads per grade"></canvas></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var stages = <?php echo $reportJson($funnel['stages']); ?>.filter(function (s) { return s.status !== 'lost'; });
    reportChart('funnelChart', {
        type: 'bar',
        data: {
            labels: stages.map(function (s) { return s.label; }),
            datasets: [{ label: 'Reached stage', data: stages.map(function (s) { return s.reached; }),
                backgroundColor: REPORT_COLORS.primary, borderRadius: 4, maxBarThickness: 28 }]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) {
                var s = stages[ctx.dataIndex];
                return s.reached + ' leads reached ' + s.label + ' (' + s.stage_conversion + '% of previous stage)';
            } } } },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: REPORT_COLORS.grid } }, y: { grid: { display: false } } }
        }
    });

    var grades = <?php echo $reportJson($grades['grades']); ?>;
    reportChart('gradeChart', {
        type: 'bar',
        data: {
            labels: grades.map(function (g) { return 'Grade ' + g.grade; }),
            datasets: [{ label: 'Leads', data: grades.map(function (g) { return g.leads; }),
                backgroundColor: REPORT_COLORS.primary, borderRadius: 4, maxBarThickness: 36 }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: REPORT_COLORS.grid } }, x: { grid: { display: false } } }
        }
    });
})();
</script>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

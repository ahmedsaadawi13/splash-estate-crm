<?php
// FILE: /app/views/reports/revenue.php
// Variables: $revenue (getRevenueByMonth), $pipeline (getDealPipeline)
require APP_PATH . '/views/reports/_header.php';

$pipelineTotals = $pipeline['totals'];
?>

<div class="stats-grid">
    <div class="stat-card">
        <h3>Revenue</h3>
        <div class="stat-number"><?php echo e(View::formatCurrency($revenue['totals']['revenue'])); ?></div>
        <div class="stat-label"><?php echo (int)$revenue['totals']['deal_count']; ?> deal(s) closed won</div>
    </div>
    <div class="stat-card">
        <h3>Commission</h3>
        <div class="stat-number"><?php echo e(View::formatCurrency($revenue['totals']['commission'])); ?></div>
        <div class="stat-label">on won deals</div>
    </div>
    <div class="stat-card">
        <h3>Open Pipeline</h3>
        <div class="stat-number"><?php echo e(View::formatCurrency($pipelineTotals['open_value'])); ?></div>
        <div class="stat-label"><?php echo (int)$pipelineTotals['open_count']; ?> open deal(s) created in period</div>
    </div>
    <div class="stat-card">
        <h3>Weighted Pipeline</h3>
        <div class="stat-number"><?php echo e(View::formatCurrency($pipelineTotals['open_weighted_value'])); ?></div>
        <div class="stat-label">value &times; probability</div>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header">
        <h3>Revenue by Month</h3>
        <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('revenue')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($revenue['totals']['deal_count'] > 0): ?>
            <div class="report-chart is-short mb-2"><canvas id="revenueChart" role="img" aria-label="Revenue by month"></canvas></div>
        <?php else: ?>
            <div class="alert alert-info">No deals were closed won in this period.</div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table report-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="num">Deals Won</th>
                        <th class="num">Revenue</th>
                        <th class="num">Commission</th>
                        <th class="num">Avg Deal Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revenue['months'] as $month): ?>
                    <tr>
                        <td><?php echo e($month['label']); ?></td>
                        <td class="num"><?php echo (int)$month['deal_count']; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($month['revenue'])); ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($month['commission'])); ?></td>
                        <td class="num"><?php echo $month['deal_count'] > 0 ? e(View::formatCurrency($month['avg_deal_value'])) : '&ndash;'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="num"><?php echo (int)$revenue['totals']['deal_count']; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($revenue['totals']['revenue'])); ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($revenue['totals']['commission'])); ?></td>
                        <td class="num"><?php echo $revenue['totals']['deal_count'] > 0 ? e(View::formatCurrency($revenue['totals']['revenue'] / $revenue['totals']['deal_count'])) : '&ndash;'; ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="small text-muted">Months are based on the deal's actual close date (falling back to its last update when no close date was recorded).</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Deal Pipeline by Stage</h3>
        <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('pipeline')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($pipelineTotals['deal_count'] > 0): ?>
            <div class="report-chart is-short mb-2"><canvas id="pipelineChart" role="img" aria-label="Deal value by stage"></canvas></div>
        <?php else: ?>
            <div class="alert alert-info">No deals were created in this period.</div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table report-table">
                <thead>
                    <tr>
                        <th>Stage</th>
                        <th class="num">Deals</th>
                        <th class="num">Total Value</th>
                        <th class="num">Avg Probability</th>
                        <th class="num">Weighted Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pipeline['stages'] as $stage): ?>
                    <tr>
                        <td><?php echo e($stage['label']); ?></td>
                        <td class="num"><?php echo (int)$stage['deal_count']; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($stage['total_value'])); ?></td>
                        <td class="num"><?php echo $stage['deal_count'] > 0 ? e(number_format($stage['avg_probability'], 1)) . '%' : '&ndash;'; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($stage['weighted_value'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>All stages</td>
                        <td class="num"><?php echo (int)$pipelineTotals['deal_count']; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($pipelineTotals['total_value'])); ?></td>
                        <td></td>
                        <td class="num"><?php echo e(View::formatCurrency($pipelineTotals['weighted_value'])); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="small text-muted">Covers deals created in the selected period. Weighted value = deal value &times; probability.</p>
    </div>
</div>

<script>
(function () {
    var months = <?php echo $reportJson($revenue['months']); ?>;
    reportChart('revenueChart', {
        type: 'bar',
        data: {
            labels: months.map(function (m) { return m.label; }),
            datasets: [{ label: 'Revenue', data: months.map(function (m) { return m.revenue; }),
                backgroundColor: REPORT_COLORS.primary, borderRadius: 4, maxBarThickness: 40 }]
        },
        options: {
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) {
                var m = months[ctx.dataIndex];
                return reportMoney(m.revenue) + ' from ' + m.deal_count + ' deal(s), commission ' + reportMoney(m.commission);
            } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: reportMoney }, grid: { color: REPORT_COLORS.grid } }, x: { grid: { display: false } } }
        }
    });

    var stages = <?php echo $reportJson($pipeline['stages']); ?>;
    reportChart('pipelineChart', {
        type: 'bar',
        data: {
            labels: stages.map(function (s) { return s.label; }),
            datasets: [
                { label: 'Total value', data: stages.map(function (s) { return s.total_value; }),
                  backgroundColor: REPORT_COLORS.primary, borderRadius: 4, maxBarThickness: 22 },
                { label: 'Weighted value', data: stages.map(function (s) { return s.weighted_value; }),
                  backgroundColor: REPORT_COLORS.secondary, borderRadius: 4, maxBarThickness: 22 }
            ]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { position: 'top', align: 'start' }, tooltip: { callbacks: { label: function (ctx) {
                var s = stages[ctx.dataIndex];
                return ctx.dataset.label + ': ' + reportMoney(ctx.raw) + ' (' + s.deal_count + ' deals)';
            } } } },
            scales: { x: { beginAtZero: true, ticks: { callback: reportMoney }, grid: { color: REPORT_COLORS.grid } }, y: { grid: { display: false } } }
        }
    });
})();
</script>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

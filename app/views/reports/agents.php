<?php
// FILE: /app/views/reports/agents.php
// Variables: $agents (ReportService::getAgentLeaderboard)
require APP_PATH . '/views/reports/_header.php';

$totals = array('leads' => 0, 'leads_won' => 0, 'deals_won' => 0, 'revenue' => 0.0, 'commission' => 0.0,
    'open_deals' => 0, 'open_value' => 0.0, 'tasks_completed' => 0);
foreach ($agents as $agentRow) {
    foreach ($totals as $key => $unused) {
        $totals[$key] += $agentRow[$key];
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3>Agent Leaderboard</h3>
        <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('agents')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($agents)): ?>
            <div class="empty-state"><p>No agents found for this organization.</p></div>
        <?php else: ?>
        <div class="report-chart is-short mb-2"><canvas id="agentChart" role="img" aria-label="Revenue per agent"></canvas></div>
        <div class="table-responsive">
            <table class="table report-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Agent</th>
                        <th class="num">Leads</th>
                        <th class="num">Leads Won</th>
                        <th class="num">Lead Conversion</th>
                        <th class="num">Deals Won</th>
                        <th class="num">Revenue</th>
                        <th class="num">Commission</th>
                        <th class="num">Open Deals</th>
                        <th class="num">Open Pipeline</th>
                        <th class="num">Tasks Completed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $agent): ?>
                    <tr>
                        <td><?php echo (int)$agent['rank']; ?></td>
                        <td>
                            <?php echo e($agent['agent_name']); ?>
                            <?php if ($agent['role'] === 'tenant_admin'): ?><span class="badge badge-info">Admin</span><?php endif; ?>
                            <?php if ($agent['status'] !== 'active'): ?><span class="badge badge-secondary">Inactive</span><?php endif; ?>
                        </td>
                        <td class="num"><?php echo (int)$agent['leads']; ?></td>
                        <td class="num"><?php echo (int)$agent['leads_won']; ?></td>
                        <td class="num"><?php echo e(number_format($agent['lead_conversion'], 1)); ?>%</td>
                        <td class="num"><?php echo (int)$agent['deals_won']; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($agent['revenue'])); ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($agent['commission'])); ?></td>
                        <td class="num"><?php echo (int)$agent['open_deals']; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($agent['open_value'])); ?></td>
                        <td class="num"><?php echo (int)$agent['tasks_completed']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td></td>
                        <td>Total</td>
                        <td class="num"><?php echo (int)$totals['leads']; ?></td>
                        <td class="num"><?php echo (int)$totals['leads_won']; ?></td>
                        <td class="num"><?php echo e(number_format($totals['leads'] > 0 ? $totals['leads_won'] / $totals['leads'] * 100 : 0, 1)); ?>%</td>
                        <td class="num"><?php echo (int)$totals['deals_won']; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($totals['revenue'])); ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($totals['commission'])); ?></td>
                        <td class="num"><?php echo (int)$totals['open_deals']; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($totals['open_value'])); ?></td>
                        <td class="num"><?php echo (int)$totals['tasks_completed']; ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="small text-muted">
            Leads = leads assigned to the agent and created in the period. Deals won, revenue and commission use deals
            closed won in the period. Open deals/pipeline is the agent's current open pipeline. Tasks completed = tasks
            assigned to the agent completed in the period. Unassigned records are not attributed to anyone.
        </p>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var agents = <?php echo $reportJson(array_map(function ($a) {
        return array('name' => $a['agent_name'], 'revenue' => $a['revenue'], 'deals_won' => $a['deals_won']);
    }, $agents)); ?>;
    reportChart('agentChart', {
        type: 'bar',
        data: {
            labels: agents.map(function (a) { return a.name; }),
            datasets: [{ label: 'Revenue', data: agents.map(function (a) { return a.revenue; }),
                backgroundColor: REPORT_COLORS.primary, borderRadius: 4, maxBarThickness: 40 }]
        },
        options: {
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) {
                var a = agents[ctx.dataIndex];
                return reportMoney(a.revenue) + ' from ' + a.deals_won + ' won deal(s)';
            } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: reportMoney }, grid: { color: REPORT_COLORS.grid } }, x: { grid: { display: false } } }
        }
    });
})();
</script>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

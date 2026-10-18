<?php
// FILE: /app/views/scoring/index.php
require APP_PATH . '/views/layouts/header.php';

$stats = $metrics['statistics'];
$ruleStats = $metrics['rule_stats'];
?>

<div class="page-header">
    <div>
        <h1>Lead Scoring</h1>
        <p class="text-muted">Score = sum of points of matching active rules, clamped to 0&ndash;100. Grades: A &ge; 80, B &ge; 60, C &ge; 40, D &ge; 20, F below.</p>
    </div>
    <div class="header-actions">
        <a href="<?php echo e(url('scoring/test')); ?>" class="btn btn-secondary">Test Rules</a>
        <form method="POST" action="<?php echo e(url('scoring/recalculate')); ?>" class="inline-form"
              onsubmit="return confirm('Recalculate the score of every lead now?');">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-warning">Recalculate All Scores</button>
        </form>
        <a href="<?php echo e(url('scoring/create')); ?>" class="btn btn-primary">+ New Rule</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?php echo (int)$ruleStats['active_rules']; ?> / <?php echo (int)$ruleStats['total_rules']; ?></div>
        <div class="stat-label">Active rules</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?php echo (int)$stats['total_scored_leads']; ?> / <?php echo (int)$stats['total_leads']; ?></div>
        <div class="stat-label">Leads scored</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?php echo e($stats['average_score']); ?></div>
        <div class="stat-label">Average score</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?php echo (int)$ruleStats['max_positive_points']; ?></div>
        <div class="stat-label">Max achievable points</div>
    </div>
</div>
<?php if (!empty($stats['last_calculated_at'])): ?>
    <p class="text-muted small">Last calculation: <?php echo e(View::formatDate($stats['last_calculated_at'])); ?></p>
<?php endif; ?>

<div class="grid-2 mt-2">
    <div class="card">
        <div class="card-header"><h3>Grade distribution</h3></div>
        <div class="card-body">
            <table class="table">
                <thead><tr><th>Grade</th><th>Leads</th><th style="width:45%">Share</th><th>Avg score</th></tr></thead>
                <tbody>
                <?php foreach ($metrics['distribution'] as $row): ?>
                    <tr>
                        <td><span class="grade grade-<?php echo e($row['grade']); ?>"><?php echo e($row['grade']); ?></span></td>
                        <td><?php echo (int)$row['count']; ?></td>
                        <td>
                            <div class="progress"><div class="progress-bar" style="width: <?php echo (float)$row['percent']; ?>%"></div></div>
                            <span class="small text-muted"><?php echo e($row['percent']); ?>%</span>
                        </td>
                        <td><?php echo $row['count'] > 0 ? e($row['avg_score']) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Conversion by grade</h3></div>
        <div class="card-body">
            <table class="table">
                <thead><tr><th>Grade</th><th>Leads</th><th>Won</th><th>Lost</th><th>Win rate</th></tr></thead>
                <tbody>
                <?php foreach ($metrics['conversion_rates'] as $row): ?>
                    <tr>
                        <td><span class="grade grade-<?php echo e($row['grade']); ?>"><?php echo e($row['grade']); ?></span></td>
                        <td><?php echo (int)$row['total_leads']; ?></td>
                        <td><?php echo (int)$row['won_leads']; ?></td>
                        <td><?php echo (int)$row['lost_leads']; ?></td>
                        <td><?php echo $row['total_leads'] > 0 ? e($row['conversion_rate']) . '%' : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="form-help">Win rate = leads with status "won" / all scored leads with that grade.</p>
        </div>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header"><h3>Top leads</h3></div>
    <div class="card-body">
        <?php if (empty($topLeads)): ?>
            <div class="empty-state"><p>No leads have been scored yet. Use "Recalculate All Scores".</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Lead</th><th>Status</th><th>Source</th><th>Score</th><th>Grade</th><th>Calculated</th></tr></thead>
                    <tbody>
                    <?php foreach ($topLeads as $lead): ?>
                        <tr>
                            <td><a href="<?php echo e(url('leads/view/' . (int)$lead['lead_id'])); ?>"><?php echo e(trim($lead['name'] ?? '')); ?></a></td>
                            <td><?php echo View::statusBadge($lead['status'] ?? ''); ?></td>
                            <td><?php echo e($lead['source'] ?? ''); ?></td>
                            <td><?php echo (int)$lead['score']; ?></td>
                            <td><span class="grade grade-<?php echo e($lead['grade'] ?? 'F'); ?>"><?php echo e($lead['grade'] ?? '-'); ?></span></td>
                            <td><?php echo e(View::formatDate($lead['last_calculated_at'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<h2 class="mt-3">Rules</h2>
<?php foreach ($ruleTypes as $type => $typeInfo): ?>
    <?php $rules = isset($groupedRules[$type]) ? $groupedRules[$type] : array(); ?>
    <div class="card mt-2">
        <div class="card-header">
            <h3><?php echo e($typeInfo['name']); ?> <span class="badge badge-secondary"><?php echo count($rules); ?></span></h3>
            <span class="small text-muted"><?php echo e($typeInfo['description']); ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($rules)): ?>
                <p class="text-muted">No <?php echo e(strtolower($typeInfo['name'])); ?> rules.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Order</th><th>Name</th><th>Condition</th><th>Points</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($rules as $rule): ?>
                            <tr>
                                <td><?php echo (int)$rule['execution_order']; ?></td>
                                <td>
                                    <strong><?php echo e($rule['name']); ?></strong>
                                    <?php if (!empty($rule['description'])): ?><div class="small text-muted"><?php echo e($rule['description']); ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <code><?php echo e(isset($fields[$rule['field_name']]) ? $fields[$rule['field_name']] : $rule['field_name']); ?></code>
                                    <?php echo e(isset($operators[$rule['operator']]) ? strtolower($operators[$rule['operator']]) : $rule['operator']); ?>
                                    <?php if (!in_array($rule['operator'], array('is_empty', 'is_not_empty'), true)): ?>
                                        <code><?php echo e($rule['field_value'] ?? ''); ?></code>
                                    <?php endif; ?>
                                </td>
                                <td class="<?php echo (int)$rule['points'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo (int)$rule['points'] > 0 ? '+' : ''; ?><?php echo (int)$rule['points']; ?>
                                </td>
                                <td><?php echo View::statusBadge($rule['status'] ?? 'inactive'); ?></td>
                                <td class="text-right">
                                    <div class="d-flex gap-1" style="justify-content:flex-end">
                                        <a href="<?php echo e(url('scoring/edit/' . (int)$rule['id'])); ?>" class="btn btn-sm btn-secondary">Edit</a>
                                        <form method="POST" action="<?php echo e(url('scoring/toggle/' . (int)$rule['id'])); ?>" class="inline-form">
                                            <?php echo csrf_field(); ?>
                                            <button type="submit" class="btn btn-sm btn-outline"><?php echo $rule['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                        </form>
                                        <form method="POST" action="<?php echo e(url('scoring/delete/' . (int)$rule['id'])); ?>" class="inline-form"
                                              onsubmit="return confirm('Delete this scoring rule?');">
                                            <?php echo csrf_field(); ?>
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

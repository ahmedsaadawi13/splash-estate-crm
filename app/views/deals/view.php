<?php
// FILE: /app/views/deals/view.php
require APP_PATH . '/views/layouts/header.php';

$dealId = (int)$deal['id'];
$stageLabel = function ($stage) use ($stages) {
    return isset($stages[$stage]) ? $stages[$stage] : ucfirst(str_replace('_', ' ', (string)$stage));
};
$value = (float)$deal['deal_value'];
$weighted = $value * (int)$deal['probability'] / 100;
$isClosed = $deal['stage'] === 'closed_won' || $deal['stage'] === 'closed_lost';
$overdue = !$isClosed && !empty($deal['expected_close_date']) && $deal['expected_close_date'] < date('Y-m-d');
$priorityBadges = array('low' => 'badge-secondary', 'medium' => 'badge-info', 'high' => 'badge-warning', 'urgent' => 'badge-danger');
?>
<div class="breadcrumb"><a href="<?php echo e(url('deals/index')); ?>">Deals</a> &rsaquo; <?php echo e($deal['title']); ?></div>
<div class="page-header">
    <div>
        <h1><?php echo e($deal['title']); ?></h1>
        <span class="badge <?php echo e(Deal::stageBadgeClass($deal['stage'])); ?>"><?php echo e($stageLabel($deal['stage'])); ?></span>
    </div>
    <div class="header-actions">
        <?php if ($canEdit): ?>
            <a href="<?php echo e(url('deals/edit/' . $dealId)); ?>" class="btn btn-primary">Edit</a>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <form method="post" action="<?php echo e(url('deals/delete/' . $dealId)); ?>" class="inline-form" onsubmit="return confirm('Delete this deal? This cannot be undone.');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        <?php endif; ?>
        <a href="<?php echo e(url('deals/index')); ?>" class="btn btn-secondary">Back to pipeline</a>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="card">
    <div class="card-body">
        <form method="post" action="<?php echo e(url('deals/updateStage/' . $dealId)); ?>" class="d-flex gap-1 align-center flex-wrap">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="return" value="view">
            <strong>Move stage:</strong>
            <?php foreach ($stages as $value_ => $label): ?>
                <button type="submit" name="stage" value="<?php echo e($value_); ?>"
                        class="btn btn-sm <?php echo $value_ === $deal['stage'] ? 'btn-primary' : ($value_ === 'closed_won' ? 'btn-success' : ($value_ === 'closed_lost' ? 'btn-danger' : 'btn-outline')); ?>"
                        <?php echo $value_ === $deal['stage'] ? 'disabled aria-current="step"' : ''; ?>><?php echo e($label); ?></button>
            <?php endforeach; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="layout-main-side">
    <div>
        <div class="card">
            <div class="card-header"><h2>Deal details</h2></div>
            <div class="card-body">
                <dl class="detail-list">
                    <dt>Deal value</dt>
                    <dd><?php echo e(View::formatCurrency($value)); ?></dd>
                    <dt>Commission</dt>
                    <dd>
                        <?php if ($deal['commission'] !== null): ?>
                            <?php echo e(View::formatCurrency((float)$deal['commission'])); ?>
                            <?php if ($value > 0): ?><span class="small text-muted">(<?php echo e(number_format((float)$deal['commission'] / $value * 100, 2)); ?>%)</span><?php endif; ?>
                        <?php else: ?>&ndash;<?php endif; ?>
                    </dd>
                    <dt>Probability</dt>
                    <dd>
                        <?php echo (int)$deal['probability']; ?>%
                        <div class="progress mt-1" style="max-width: 220px;"><div class="progress-bar" style="width: <?php echo max(0, min(100, (int)$deal['probability'])); ?>%;"></div></div>
                    </dd>
                    <dt>Weighted value</dt>
                    <dd><?php echo e(View::formatCurrency($weighted)); ?></dd>
                    <dt>Expected close</dt>
                    <dd><?php echo e(View::formatDate($deal['expected_close_date'], 'M j, Y')); ?><?php if ($overdue): ?> <span class="badge badge-danger">Overdue</span><?php endif; ?></dd>
                    <dt>Actual close</dt>
                    <dd><?php echo e(View::formatDate($deal['actual_close_date'], 'M j, Y')); ?></dd>
                    <dt>Created</dt>
                    <dd><?php echo e(View::formatDate($deal['created_at'], 'M j, Y H:i')); ?></dd>
                    <dt>Last updated</dt>
                    <dd><?php echo e(View::formatDate($deal['updated_at'], 'M j, Y H:i')); ?></dd>
                </dl>
                <?php if (!empty($deal['notes'])): ?>
                    <h3 class="mt-2 mb-1">Notes</h3>
                    <p><?php echo nl2br(e($deal['notes'])); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Tasks (<?php echo count($tasks); ?>)</h2>
                <?php if (can('tasks.create')): ?>
                    <a href="<?php echo e(url('tasks/create') . '?related_to_type=deal&related_to_id=' . $dealId); ?>" class="btn btn-sm btn-outline">+ Add Task</a>
                <?php endif; ?>
            </div>
            <?php if (empty($tasks)): ?>
                <div class="card-body text-muted">No tasks linked to this deal.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Task</th><th>Due</th><th>Priority</th><th>Status</th><th>Assigned</th></tr></thead>
                    <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td><a href="<?php echo e(url('tasks/view/' . (int)$task['id'])); ?>"><?php echo e($task['title']); ?></a></td>
                            <td><?php echo e(View::formatDate($task['due_date'], 'M j, Y H:i')); ?></td>
                            <td><span class="badge <?php echo e(isset($priorityBadges[$task['priority']]) ? $priorityBadges[$task['priority']] : 'badge-secondary'); ?>"><?php echo e(ucfirst((string)$task['priority'])); ?></span></td>
                            <td><?php echo View::statusBadge((string)$task['status']); ?></td>
                            <td><?php echo e($task['assigned_name'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><h2>Stage history</h2></div>
            <div class="card-body">
                <ul class="timeline">
                    <?php foreach ($history as $entry): ?>
                        <li>
                            <strong><?php echo e($stageLabel($entry['from_stage'])); ?></strong> &rarr; <strong><?php echo e($stageLabel($entry['to_stage'])); ?></strong>
                            <div class="timeline-meta"><?php echo e(View::formatDate($entry['created_at'], 'M j, Y H:i')); ?><?php if (!empty($entry['user_name'])): ?> &middot; <?php echo e($entry['user_name']); ?><?php endif; ?></div>
                        </li>
                    <?php endforeach; ?>
                    <li>
                        Deal created
                        <div class="timeline-meta"><?php echo e(View::formatDate($deal['created_at'], 'M j, Y H:i')); ?></div>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header"><h2>Client</h2></div>
            <div class="card-body">
                <?php if (!empty($deal['client_id']) && trim((string)$deal['client_name']) !== ''): ?>
                    <a href="<?php echo e(url('clients/view/' . (int)$deal['client_id'])); ?>"><strong><?php echo e(trim($deal['client_name'])); ?></strong></a>
                    <?php if (!empty($deal['client_email'])): ?><br><a href="mailto:<?php echo e($deal['client_email']); ?>"><?php echo e($deal['client_email']); ?></a><?php endif; ?>
                    <?php if (!empty($deal['client_phone'])): ?><br><a href="tel:<?php echo e($deal['client_phone']); ?>"><?php echo e($deal['client_phone']); ?></a><?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">No client linked.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Property</h2></div>
            <div class="card-body">
                <?php if (!empty($deal['property_id']) && !empty($deal['property_title'])): ?>
                    <a href="<?php echo e(url('properties/view/' . (int)$deal['property_id'])); ?>"><strong><?php echo e($deal['property_title']); ?></strong></a>
                    <br><span class="text-muted"><?php echo e($deal['property_address'] . ', ' . $deal['property_city']); ?></span>
                    <br><?php echo e(View::formatCurrency((float)$deal['property_price'])); ?>
                    <span class="badge badge-primary"><?php echo e(ucfirst((string)$deal['property_listing_type'])); ?></span>
                <?php else: ?>
                    <span class="text-muted">No property linked.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Assigned to</h2></div>
            <div class="card-body">
                <?php if (!empty($deal['agent_name'])): ?>
                    <strong><?php echo e($deal['agent_name']); ?></strong>
                    <?php if (!empty($deal['agent_email'])): ?><br><a href="mailto:<?php echo e($deal['agent_email']); ?>"><?php echo e($deal['agent_email']); ?></a><?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">Unassigned.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

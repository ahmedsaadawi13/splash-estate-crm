<?php
// FILE: /app/views/dashboard/index.php
require APP_PATH . '/views/layouts/header.php';
$priorityBadges = array('urgent' => 'badge-danger', 'high' => 'badge-danger', 'medium' => 'badge-warning', 'low' => 'badge-info');
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <?php if (empty($noTenant)): ?>
    <div class="header-actions">
        <?php if (can('leads.create')): ?><a href="<?php echo e(url('leads/create')); ?>" class="btn btn-primary">New Lead</a><?php endif; ?>
        <?php if (can('tasks.create')): ?><a href="<?php echo e(url('tasks/create')); ?>" class="btn btn-outline">New Task</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($noTenant)): ?>
    <div class="card">
        <div class="card-body">
            <p>You are signed in as a platform administrator. Agency data (leads, clients, deals, tasks) is only visible to agency users.</p>
        </div>
    </div>
<?php else: ?>

<div class="stats-grid">
    <div class="stat-card">
        <h3>Total Leads</h3>
        <p class="stat-number"><a href="<?php echo e(url('leads/index')); ?>"><?php echo (int)$stats['total_leads']; ?></a></p>
        <span class="stat-label"><a href="<?php echo e(url('leads/index') . '?status=new'); ?>"><?php echo (int)$stats['new_leads']; ?> new</a></span>
    </div>

    <div class="stat-card">
        <h3>Clients</h3>
        <p class="stat-number"><a href="<?php echo e(url('clients/index')); ?>"><?php echo (int)$stats['total_clients']; ?></a></p>
    </div>

    <div class="stat-card">
        <h3>Properties</h3>
        <p class="stat-number"><?php echo (int)$stats['total_properties']; ?></p>
        <span class="stat-label"><?php echo (int)$stats['available_properties']; ?> available</span>
    </div>

    <div class="stat-card">
        <h3>Active Deals</h3>
        <p class="stat-number"><?php echo (int)$stats['active_deals']; ?></p>
        <span class="stat-label"><?php echo (int)$stats['total_deals']; ?> total</span>
    </div>

    <div class="stat-card">
        <h3>My Open Tasks</h3>
        <p class="stat-number"><a href="<?php echo e(url('tasks/index') . '?assigned_to=me&status=open'); ?>"><?php echo (int)$stats['my_tasks']; ?></a></p>
        <span class="stat-label <?php echo $stats['my_overdue'] > 0 ? 'text-danger' : ''; ?>"><?php echo (int)$stats['my_overdue']; ?> overdue</span>
    </div>

    <?php if ($subscription): ?>
    <div class="stat-card">
        <h3>Subscription</h3>
        <p class="stat-label"><?php echo e($subscription['plan_name']); ?></p>
        <small>Leads: <?php echo (int)$usage['leads_count']; ?>/<?php echo (int)$subscription['max_leads'] === -1 ? '&infin;' : (int)$subscription['max_leads']; ?></small>
    </div>
    <?php else: ?>
    <div class="stat-card">
        <h3>Subscription</h3>
        <p class="stat-label text-danger">No active plan</p>
        <?php if (can('subscription.view')): ?><small><a href="<?php echo e(url('subscription/index')); ?>">Manage subscription</a></small><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div class="card mb-2">
        <div class="card-header d-flex justify-between align-center">
            <h2>Hot Leads</h2>
            <a href="<?php echo e(url('leads/index') . '?sort=score&direction=DESC'); ?>" class="btn btn-link btn-sm">All by score</a>
        </div>
        <div class="card-body">
            <?php if (!empty($hot_leads)): ?>
                <table class="table">
                    <thead><tr><th>Lead</th><th>Grade</th><th>Score</th><th>Status</th><th>Agent</th></tr></thead>
                    <tbody>
                    <?php foreach ($hot_leads as $lead): ?>
                        <tr>
                            <td><a href="<?php echo e(url('leads/view/' . (int)$lead['id'])); ?>"><?php echo e(trim($lead['first_name'] . ' ' . (string)$lead['last_name'])); ?></a></td>
                            <td><span class="grade grade-<?php echo e($lead['grade']); ?>"><?php echo e($lead['grade']); ?></span></td>
                            <td>
                                <?php echo (int)$lead['score']; ?>
                                <div class="progress"><div class="progress-bar" style="width: <?php echo max(0, min(100, (int)$lead['score'])); ?>%;"></div></div>
                            </td>
                            <td><?php echo View::statusBadge($lead['status']); ?></td>
                            <td><?php echo e($lead['assigned_to_name'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted">No A or B grade leads right now.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-header d-flex justify-between align-center">
            <h2>My Overdue Tasks</h2>
            <a href="<?php echo e(url('tasks/index') . '?assigned_to=me&overdue=1'); ?>" class="btn btn-link btn-sm">View all</a>
        </div>
        <div class="card-body">
            <?php if (!empty($overdue_tasks)): ?>
                <ul class="task-list">
                    <?php foreach ($overdue_tasks as $task): ?>
                    <li>
                        <div>
                            <a href="<?php echo e(url('tasks/view/' . (int)$task['id'])); ?>"><?php echo e($task['title']); ?></a>
                            <span class="badge <?php echo isset($priorityBadges[$task['priority']]) ? $priorityBadges[$task['priority']] : 'badge-secondary'; ?>"><?php echo e(ucfirst((string)$task['priority'])); ?></span>
                        </div>
                        <span class="task-due text-danger"><?php echo View::formatDate($task['due_date']); ?></span>
                        <?php if (can('tasks.edit')): ?>
                        <form method="POST" action="<?php echo e(url('tasks/complete/' . (int)$task['id'])); ?>" class="inline-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="return_to" value="dashboard/index">
                            <button type="submit" class="btn btn-sm btn-success">Done</button>
                        </form>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">Nothing overdue. Nice work!</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-header d-flex justify-between align-center">
            <h2>Recent Leads</h2>
            <a href="<?php echo e(url('leads/index')); ?>" class="btn btn-link btn-sm">View all</a>
        </div>
        <div class="card-body">
            <?php if (!empty($recent_leads)): ?>
                <table class="table">
                    <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_leads as $lead): ?>
                        <tr>
                            <td>
                                <a href="<?php echo e(url('leads/view/' . (int)$lead['id'])); ?>"><?php echo e(trim($lead['first_name'] . ' ' . (string)$lead['last_name'])); ?></a>
                                <?php if (!empty($lead['grade'])): ?><span class="grade grade-<?php echo e($lead['grade']); ?>"><?php echo e($lead['grade']); ?></span><?php endif; ?>
                            </td>
                            <td><?php echo e($lead['email'] ?? ''); ?></td>
                            <td><?php echo View::statusBadge($lead['status']); ?></td>
                            <td><?php echo View::formatDate($lead['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted">No recent leads.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-header d-flex justify-between align-center">
            <h2>My Upcoming Tasks</h2>
            <a href="<?php echo e(url('tasks/index') . '?assigned_to=me&status=open'); ?>" class="btn btn-link btn-sm">View all</a>
        </div>
        <div class="card-body">
            <?php if (!empty($upcoming_tasks)): ?>
                <ul class="task-list">
                    <?php foreach ($upcoming_tasks as $task): ?>
                    <li>
                        <a href="<?php echo e(url('tasks/view/' . (int)$task['id'])); ?>"><?php echo e($task['title']); ?></a>
                        <span class="task-due"><?php echo View::formatDate($task['due_date']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">No upcoming tasks.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($recent_deals) && can('deals.view')): ?>
    <div class="card mb-2">
        <div class="card-header d-flex justify-between align-center">
            <h2>Recent Deals</h2>
            <a href="<?php echo e(url('deals/index')); ?>" class="btn btn-link btn-sm">View all</a>
        </div>
        <div class="card-body">
            <table class="table">
                <thead><tr><th>Deal</th><th>Stage</th><th class="text-right">Value</th></tr></thead>
                <tbody>
                <?php foreach ($recent_deals as $deal): ?>
                    <tr>
                        <td><a href="<?php echo e(url('deals/view/' . (int)$deal['id'])); ?>"><?php echo e($deal['title']); ?></a></td>
                        <td><span class="badge badge-info"><?php echo e(ucwords(str_replace('_', ' ', (string)$deal['stage']))); ?></span></td>
                        <td class="text-right"><?php echo $deal['deal_value'] !== null ? View::formatCurrency($deal['deal_value']) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

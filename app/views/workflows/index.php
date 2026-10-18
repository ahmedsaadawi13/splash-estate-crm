<?php
// FILE: /app/views/workflows/index.php
require APP_PATH . '/views/layouts/header.php';
require_once APP_PATH . '/views/workflows/_helpers.php';
?>
<div class="page-header">
    <div>
        <h1>Workflows</h1>
        <p class="text-muted small">Automate follow-ups, assignments and notifications when things happen in your CRM.</p>
    </div>
    <div class="header-actions">
        <a href="<?php echo e(url('workflows/create')); ?>" class="btn btn-primary">Create workflow</a>
    </div>
</div>

<div class="filters">
    <form method="GET" action="<?php echo e(url('workflows/index')); ?>" class="filter-form">
        <select name="status" class="form-control">
            <option value="">All statuses</option>
            <option value="active"<?php echo $filters['status'] === 'active' ? ' selected' : ''; ?>>Active</option>
            <option value="inactive"<?php echo $filters['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
        </select>
        <select name="trigger" class="form-control">
            <option value="">All triggers</option>
            <?php foreach ($triggerTypes as $value => $label): ?>
                <option value="<?php echo e($value); ?>"<?php echo $filters['trigger_type'] === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if ($filters['status'] !== '' || $filters['trigger_type'] !== ''): ?>
            <a href="<?php echo e(url('workflows/index')); ?>" class="btn btn-link">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if (empty($workflows)): ?>
        <div class="empty-state">
            <h3>No workflows found</h3>
            <p>Create a workflow to automate repetitive work, e.g. notify an agent and create a follow-up task for every new lead.</p>
            <a href="<?php echo e(url('workflows/create')); ?>" class="btn btn-primary mt-2">Create workflow</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Trigger</th>
                        <th>Actions</th>
                        <th>Status</th>
                        <th>Runs</th>
                        <th>Last run</th>
                        <th class="text-right">Manage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workflows as $wf): ?>
                        <?php $wfId = (int)$wf['id']; ?>
                        <tr>
                            <td>
                                <a href="<?php echo e(url('workflows/edit/' . $wfId)); ?>"><strong><?php echo e($wf['name']); ?></strong></a>
                                <?php if (!empty($wf['description'])): ?>
                                    <div class="small text-muted"><?php echo e(View::truncate((string)$wf['description'], 90)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e(wf_trigger_summary($wf, $triggerTypes)); ?></td>
                            <td><?php echo (int)$wf['action_count']; ?></td>
                            <td><?php echo wf_status_badge($wf['status']); ?></td>
                            <td>
                                <?php echo (int)$wf['total_executions']; ?>
                                <?php if ((int)$wf['total_executions'] > 0): ?>
                                    <div class="small">
                                        <span class="text-success"><?php echo (int)$wf['successful_executions']; ?> ok</span>
                                        <?php if ((int)$wf['failed_executions'] > 0): ?>
                                            / <span class="text-danger"><?php echo (int)$wf['failed_executions']; ?> failed</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?php echo e(View::formatDate($wf['last_execution'] ?? '')); ?></td>
                            <td class="text-right">
                                <div class="d-flex gap-1" style="justify-content:flex-end;">
                                    <a href="<?php echo e(url('workflows/edit/' . $wfId)); ?>" class="btn btn-sm btn-secondary">Edit</a>
                                    <a href="<?php echo e(url('workflows/executions/' . $wfId)); ?>" class="btn btn-sm btn-outline">History</a>
                                    <form method="POST" action="<?php echo e(url('workflows/toggle/' . $wfId)); ?>" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-sm btn-outline"><?php echo $wf['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                    </form>
                                    <form method="POST" action="<?php echo e(url('workflows/delete/' . $wfId)); ?>" class="inline-form" onsubmit="return confirm('Delete this workflow and its run history?');">
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

<?php echo wf_pager($page, $totalPages, 'workflows/index', array('status' => $filters['status'], 'trigger' => $filters['trigger_type'])); ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

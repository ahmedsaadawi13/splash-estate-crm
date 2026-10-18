<?php
// FILE: /app/views/workflows/executions.php
require APP_PATH . '/views/layouts/header.php';
require_once APP_PATH . '/views/workflows/_helpers.php';
$workflowId = (int)$workflow['id'];
?>
<div class="page-header">
    <div>
        <div class="breadcrumb">
            <a href="<?php echo e(url('workflows/index')); ?>">Workflows</a> /
            <a href="<?php echo e(url('workflows/edit/' . $workflowId)); ?>"><?php echo e($workflow['name']); ?></a> / History
        </div>
        <h1>Run history</h1>
        <p class="text-muted small"><?php echo e(wf_trigger_summary($workflow, $triggerTypes)); ?> &middot; <?php echo wf_status_badge($workflow['status']); ?></p>
    </div>
    <div class="header-actions">
        <a href="<?php echo e(url('workflows/edit/' . $workflowId)); ?>" class="btn btn-secondary">Edit workflow</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-number"><?php echo (int)$stats['total_executions']; ?></div><div class="stat-label">Total runs</div></div>
    <div class="stat-card"><div class="stat-number text-success"><?php echo (int)$stats['successful']; ?></div><div class="stat-label">Completed</div></div>
    <div class="stat-card"><div class="stat-number text-danger"><?php echo (int)$stats['failed']; ?></div><div class="stat-label">Failed</div></div>
    <div class="stat-card"><div class="stat-number"><?php echo (int)$stats['running']; ?></div><div class="stat-label">In progress</div></div>
</div>

<div class="card">
    <?php if (empty($executions)): ?>
        <div class="empty-state">
            <h3>No runs yet</h3>
            <p>This workflow has not been triggered. Use "Test run" on the edit page to try it.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Record</th>
                        <th>Status</th>
                        <th>Actions</th>
                        <th>Started</th>
                        <th>Finished</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($executions as $ex): ?>
                        <?php
                        $data = WorkflowEngine::decodeJson($ex['trigger_data']);
                        $recordLabel = '-';
                        if (!empty($data['entity_type']) && !empty($data['entity_id'])) {
                            $recordLabel = ucfirst($data['entity_type']) . ' #' . (int)$data['entity_id'];
                            if (!empty($data['full_name']) || !empty($data['title'])) {
                                $recordLabel .= ' - ' . (!empty($data['full_name']) ? $data['full_name'] : $data['title']);
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo (int)$ex['id']; ?></td>
                            <td>
                                <?php echo e($recordLabel); ?>
                                <?php if (!empty($data['_test_run'])): ?> <span class="badge badge-info">Test</span><?php endif; ?>
                            </td>
                            <td><?php echo wf_status_badge($ex['status']); ?></td>
                            <td class="small">
                                <?php echo (int)$ex['action_completed']; ?>/<?php echo (int)$ex['action_total']; ?> done
                                <?php if ((int)$ex['action_failed'] > 0): ?>, <span class="text-danger"><?php echo (int)$ex['action_failed']; ?> failed</span><?php endif; ?>
                                <?php if ((int)$ex['action_scheduled'] > 0): ?>, <span class="text-muted"><?php echo (int)$ex['action_scheduled']; ?> scheduled</span><?php endif; ?>
                            </td>
                            <td class="small"><?php echo e(View::formatDate($ex['started_at'] ?? '')); ?></td>
                            <td class="small"><?php echo e(View::formatDate($ex['completed_at'] ?? '')); ?></td>
                            <td class="text-right"><a href="<?php echo e(url('workflows/execution/' . (int)$ex['id'])); ?>" class="btn btn-sm btn-outline">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php echo wf_pager($page, $totalPages, 'workflows/executions/' . $workflowId); ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

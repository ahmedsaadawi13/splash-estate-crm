<?php
// FILE: /app/views/workflows/execution_detail.php
require APP_PATH . '/views/layouts/header.php';
require_once APP_PATH . '/views/workflows/_helpers.php';
$workflowId = (int)$execution['workflow_id'];
$entityType = isset($triggerData['entity_type']) ? (string)$triggerData['entity_type'] : '';
$entityId = isset($triggerData['entity_id']) ? (int)$triggerData['entity_id'] : 0;
$recordLink = WorkflowEngine::entityLink($entityType, $entityId);
$displayData = $triggerData;
unset($displayData['_test_run']);
?>
<div class="page-header">
    <div>
        <div class="breadcrumb">
            <a href="<?php echo e(url('workflows/index')); ?>">Workflows</a> /
            <a href="<?php echo e(url('workflows/edit/' . $workflowId)); ?>"><?php echo e($execution['workflow_name']); ?></a> /
            <a href="<?php echo e(url('workflows/executions/' . $workflowId)); ?>">History</a> / #<?php echo (int)$execution['id']; ?>
        </div>
        <h1>Execution #<?php echo (int)$execution['id']; ?> <?php echo wf_status_badge($execution['status']); ?>
            <?php if (!empty($triggerData['_test_run'])): ?><span class="badge badge-info">Test run</span><?php endif; ?>
        </h1>
    </div>
    <div class="header-actions">
        <a href="<?php echo e(url('workflows/executions/' . $workflowId)); ?>" class="btn btn-secondary">Back to history</a>
    </div>
</div>

<div class="layout-main-side">
    <div>
        <div class="card">
            <div class="card-header"><h2>Actions</h2></div>
            <?php if (empty($actionLogs)): ?>
                <div class="card-body"><p class="text-muted">No actions were run.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Step</th>
                                <th>Action</th>
                                <th>Status</th>
                                <th>Result</th>
                                <th>Timing</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($actionLogs as $i => $log): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td>
                                        <?php echo e(isset($actionTypes[$log['action_type']]) ? $actionTypes[$log['action_type']]['name'] : $log['action_type']); ?>
                                        <?php if ((int)$log['delay_minutes'] > 0): ?>
                                            <div class="small text-muted">delay <?php echo (int)$log['delay_minutes']; ?> min</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo wf_status_badge($log['status']); ?></td>
                                    <td>
                                        <?php if (!empty($log['result'])): ?><div><?php echo e($log['result']); ?></div><?php endif; ?>
                                        <?php if (!empty($log['error_message'])): ?><div class="text-danger"><?php echo e($log['error_message']); ?></div><?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php if ($log['status'] === 'scheduled'): ?>
                                            Due <?php echo e(View::formatDate($log['scheduled_for'] ?? '')); ?>
                                        <?php else: ?>
                                            <?php if (!empty($log['scheduled_for'])): ?>Scheduled <?php echo e(View::formatDate($log['scheduled_for'])); ?><br><?php endif; ?>
                                            Started <?php echo e(View::formatDate($log['started_at'] ?? '')); ?><br>
                                            Finished <?php echo e(View::formatDate($log['completed_at'] ?? '')); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><h2>Trigger data</h2></div>
            <div class="card-body">
                <pre class="code-block" style="max-height:400px;overflow:auto;"><?php echo e(json_encode($displayData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)); ?></pre>
            </div>
        </div>
    </div>

    <aside>
        <div class="card">
            <div class="card-header"><h2>Summary</h2></div>
            <div class="card-body">
                <dl class="detail-list">
                    <dt>Workflow</dt><dd><a href="<?php echo e(url('workflows/edit/' . $workflowId)); ?>"><?php echo e($execution['workflow_name']); ?></a></dd>
                    <dt>Trigger</dt><dd><?php echo e(isset($triggerTypes[$execution['trigger_type']]) ? $triggerTypes[$execution['trigger_type']] : $execution['trigger_type']); ?></dd>
                    <dt>Record</dt>
                    <dd>
                        <?php if ($recordLink !== null): ?>
                            <a href="<?php echo e(url($recordLink)); ?>"><?php echo e(ucfirst($entityType) . ' #' . $entityId); ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </dd>
                    <dt>Status</dt><dd><?php echo wf_status_badge($execution['status']); ?></dd>
                    <dt>Started</dt><dd><?php echo e(View::formatDate($execution['started_at'] ?? '')); ?></dd>
                    <dt>Finished</dt><dd><?php echo e(View::formatDate($execution['completed_at'] ?? '')); ?></dd>
                </dl>
                <?php if (!empty($execution['error_message'])): ?>
                    <div class="alert alert-error mt-2"><?php echo e($execution['error_message']); ?></div>
                <?php endif; ?>
                <?php if ($execution['status'] === 'running'): ?>
                    <p class="small text-muted mt-2">Waiting for delayed actions; they run on the next scheduler (cron) pass after they are due.</p>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

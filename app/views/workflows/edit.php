<?php
// FILE: /app/views/workflows/edit.php
require APP_PATH . '/views/layouts/header.php';
require_once APP_PATH . '/views/workflows/_helpers.php';
$workflowId = (int)$workflow['id'];
$testEntity = $entityType;
?>
<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('workflows/index')); ?>">Workflows</a> / Edit</div>
        <h1><?php echo e($workflow['name']); ?> <?php echo wf_status_badge($workflow['status']); ?></h1>
    </div>
    <div class="header-actions">
        <a href="<?php echo e(url('workflows/executions/' . $workflowId)); ?>" class="btn btn-secondary">Run history</a>
        <form method="POST" action="<?php echo e(url('workflows/toggle/' . $workflowId)); ?>" class="inline-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="return" value="edit">
            <button type="submit" class="btn btn-outline"><?php echo $workflow['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
        </form>
        <form method="POST" action="<?php echo e(url('workflows/delete/' . $workflowId)); ?>" class="inline-form" onsubmit="return confirm('Delete this workflow and its run history?');">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-danger">Delete</button>
        </form>
    </div>
</div>

<div class="layout-main-side">
    <div>
        <?php require APP_PATH . '/views/workflows/_form.php'; ?>
    </div>

    <aside>
        <div class="card">
            <div class="card-header"><h2>Test run</h2></div>
            <div class="card-body">
                <form method="POST" action="<?php echo e(url('workflows/test/' . $workflowId)); ?>">
                    <?php echo csrf_field(); ?>
                    <?php if ($testEntity !== ''): ?>
                        <div class="form-group">
                            <label for="wf-sample-id"><?php echo e(ucfirst($testEntity)); ?> ID</label>
                            <input type="number" class="form-control" id="wf-sample-id" name="sample_id" min="1" placeholder="Newest <?php echo e($testEntity); ?>">
                            <span class="form-help">Leave empty to use your newest <?php echo e($testEntity); ?> (or sample data if you have none).</span>
                        </div>
                    <?php else: ?>
                        <p class="small text-muted">This workflow does not run for a record; the test uses empty data.</p>
                    <?php endif; ?>
                    <div class="form-group">
                        <label><input type="checkbox" name="dry_run" value="1" checked> Dry run (preview only, change nothing)</label>
                    </div>
                    <p class="form-help mb-2">Uses the saved version of the workflow. Conditions are checked; action delays are ignored. Untick "dry run" to really perform the actions.</p>
                    <button type="submit" class="btn btn-primary btn-block">Run test</button>
                </form>
            </div>
        </div>

        <?php if (!empty($stats)): ?>
            <div class="card">
                <div class="card-header"><h2>Statistics</h2></div>
                <div class="card-body">
                    <dl class="detail-list">
                        <dt>Runs</dt><dd><?php echo (int)$stats['total_executions']; ?></dd>
                        <dt>Completed</dt><dd class="text-success"><?php echo (int)$stats['successful']; ?></dd>
                        <dt>Failed</dt><dd class="text-danger"><?php echo (int)$stats['failed']; ?></dd>
                        <dt>In progress</dt><dd><?php echo (int)$stats['running']; ?></dd>
                        <dt>Last run</dt><dd><?php echo e(View::formatDate($stats['last_execution'] ?? '')); ?></dd>
                        <?php if ($workflow['trigger_type'] === 'scheduled'): ?>
                            <dt>Last schedule</dt><dd><?php echo e(View::formatDate($workflow['last_run_at'] ?? '')); ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

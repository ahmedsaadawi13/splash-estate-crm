<?php
// FILE: /app/views/webhooks/logs.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('webhooks/index')); ?>">Webhooks</a> / Delivery log</div>
        <h1>Webhook Delivery Log</h1>
    </div>
</div>

<div class="filters card">
    <form method="GET" action="<?php echo e(url('webhooks/logs')); ?>" class="filter-form card-body d-flex gap-2 flex-wrap align-center">
        <div class="form-group">
            <label for="webhook_id">Webhook</label>
            <select id="webhook_id" name="webhook_id" class="form-control">
                <option value="">All webhooks</option>
                <?php foreach ($webhooks as $hook): ?>
                    <option value="<?php echo (int)$hook['id']; ?>" <?php echo (int)$filters['webhook_id'] === (int)$hook['id'] ? 'selected' : ''; ?>>
                        <?php echo e($hook['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status" class="form-control">
                <?php foreach (array('' => 'All statuses', 'success' => 'Delivered', 'failed' => 'Failed', 'retrying' => 'Retried', 'pending' => 'Pending') as $value => $label): ?>
                    <option value="<?php echo e($value); ?>" <?php echo $filters['status'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="<?php echo e(url('webhooks/logs')); ?>" class="btn btn-link">Reset</a>
        </div>
    </form>
</div>

<div class="card mt-2">
    <div class="card-header"><h2><?php echo (int)$total; ?> deliveries</h2></div>
    <?php
    $showWebhook = true;
    require APP_PATH . '/views/webhooks/_log_table.php';
    ?>
    <?php if ($totalPages > 1): ?>
        <div class="card-footer"><?php echo View::pagination($currentPage, $totalPages, url('webhooks/logs')); ?></div>
    <?php endif; ?>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

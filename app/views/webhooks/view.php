<?php
// FILE: /app/views/webhooks/view.php
require APP_PATH . '/views/layouts/header.php';
$webhookId = (int)$webhook['id'];
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('webhooks/index')); ?>">Webhooks</a> / <?php echo e($webhook['name']); ?></div>
        <h1><?php echo e($webhook['name']); ?> <?php echo View::statusBadge($webhook['status']); ?></h1>
    </div>
    <div class="header-actions">
        <form method="POST" action="<?php echo e(url('webhooks/test/' . $webhookId)); ?>" class="inline-form">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-primary">Send test event</button>
        </form>
        <a href="<?php echo e(url('webhooks/edit/' . $webhookId)); ?>" class="btn btn-secondary">Edit</a>
        <form method="POST" action="<?php echo e(url('webhooks/toggle/' . $webhookId)); ?>" class="inline-form">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-outline"><?php echo $webhook['status'] === 'active' ? 'Disable' : 'Enable'; ?></button>
        </form>
        <form method="POST" action="<?php echo e(url('webhooks/delete/' . $webhookId)); ?>" class="inline-form"
              onsubmit="return confirm('Delete this webhook and its delivery log?');">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-danger">Delete</button>
        </form>
    </div>
</div>

<?php if ($revealedSecret !== null): ?>
    <div class="alert alert-warning">
        <strong>Signing secret — copy it now, it will not be shown again:</strong>
        <pre class="mt-1" id="revealed-secret"><?php echo e($revealedSecret); ?></pre>
    </div>
<?php endif; ?>

<div class="layout-main-side">
    <div class="card">
        <div class="card-header"><h2>Configuration</h2></div>
        <div class="card-body">
            <dl class="detail-list">
                <dt>Endpoint URL</dt>
                <dd><code><?php echo e($webhook['url']); ?></code></dd>

                <dt>Events</dt>
                <dd>
                    <?php if (in_array('*', $events, true)): ?>
                        <span class="badge badge-info">All events (*)</span>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <span class="badge badge-secondary" title="<?php echo e(isset($availableEvents[$event]) ? $availableEvents[$event] : ''); ?>"><?php echo e($event); ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </dd>

                <dt>Signing secret</dt>
                <dd>
                    <code><?php echo e($maskedSecret !== '' ? $maskedSecret : 'none'); ?></code>
                    <form method="POST" action="<?php echo e(url('webhooks/regenerate-secret/' . $webhookId)); ?>" class="inline-form"
                          onsubmit="return confirm('Generate a new secret? The current one stops working immediately.');">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-sm btn-link">Regenerate secret</button>
                    </form>
                </dd>

                <dt>Retries</dt>
                <dd>
                    <?php if (!empty($webhook['retry_enabled'])): ?>
                        Up to <?php echo (int)$webhook['max_retries']; ?> attempts, exponential backoff
                    <?php else: ?>
                        Disabled
                    <?php endif; ?>
                </dd>

                <dt>Created</dt>
                <dd><?php echo e(View::formatDate($webhook['created_at'])); ?></dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Deliveries</h2></div>
        <div class="card-body">
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-number text-success"><?php echo (int)$statusCounts['success']; ?></div><div class="stat-label">Delivered</div></div>
                <div class="stat-card"><div class="stat-number text-danger"><?php echo (int)$statusCounts['failed']; ?></div><div class="stat-label">Failed</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo (int)$statusCounts['retrying']; ?></div><div class="stat-label">Retried</div></div>
                <div class="stat-card"><div class="stat-number text-warning"><?php echo (int)$statusCounts['pending']; ?></div><div class="stat-label">Pending</div></div>
            </div>
            <p class="small text-muted mt-2">
                Requests time out after 5 seconds. Respond with any 2xx status to acknowledge.
                Use the <code>X-Webhook-Delivery</code> header (the event id) to ignore duplicates.
            </p>
        </div>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header d-flex justify-between align-center">
        <h2>Recent deliveries</h2>
        <a href="<?php echo e(url('webhooks/logs?webhook_id=' . $webhookId)); ?>" class="btn btn-sm btn-outline">Full log</a>
    </div>
    <?php
    $showWebhook = false;
    require APP_PATH . '/views/webhooks/_log_table.php';
    ?>
    <?php if ($totalPages > 1): ?>
        <div class="card-footer"><?php echo View::pagination($currentPage, $totalPages, url('webhooks/view/' . $webhookId)); ?></div>
    <?php endif; ?>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

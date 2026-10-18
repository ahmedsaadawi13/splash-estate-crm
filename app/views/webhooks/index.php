<?php
// FILE: /app/views/webhooks/index.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <h1>Webhooks</h1>
    <div class="header-actions">
        <a href="<?php echo e(url('webhooks/logs')); ?>" class="btn btn-secondary">Delivery Log</a>
        <a href="<?php echo e(url('webhooks/create')); ?>" class="btn btn-primary">+ New Webhook</a>
    </div>
</div>

<p class="text-muted">
    Webhooks send a signed JSON <code>POST</code> to your endpoint whenever something happens in the CRM
    (lead created, deal won, task completed …). Verify the <code>X-Webhook-Signature</code> header
    (<code>t=&lt;timestamp&gt;,v1=&lt;HMAC-SHA256 of "timestamp.body"&gt;</code>) with your signing secret.
</p>

<?php if (empty($webhooks)): ?>
    <div class="card">
        <div class="card-body empty-state">
            <h3>No webhooks yet</h3>
            <p class="text-muted">Connect the CRM to Zapier, Slack, your website or any HTTP endpoint.</p>
            <a href="<?php echo e(url('webhooks/create')); ?>" class="btn btn-primary">Create your first webhook</a>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Endpoint</th>
                        <th>Events</th>
                        <th>Status</th>
                        <th class="text-right">Deliveries</th>
                        <th>Last attempt</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($webhooks as $webhook): ?>
                        <?php $events = Webhook::parseEvents($webhook['events']); ?>
                        <tr>
                            <td>
                                <a href="<?php echo e(url('webhooks/view/' . (int)$webhook['id'])); ?>"><strong><?php echo e($webhook['name']); ?></strong></a>
                                <?php if (!empty($webhook['first_name'])): ?>
                                    <div class="small text-muted">by <?php echo e($webhook['first_name'] . ' ' . $webhook['last_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><code><?php echo e(View::truncate($webhook['url'], 60)); ?></code></td>
                            <td class="small">
                                <?php if (in_array('*', $events, true)): ?>
                                    <span class="badge badge-info">All events</span>
                                <?php else: ?>
                                    <?php echo e(View::truncate(implode(', ', $events), 80)); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo View::statusBadge($webhook['status']); ?></td>
                            <td class="text-right small">
                                <?php echo (int)$webhook['total_deliveries']; ?>
                                <?php if ((int)$webhook['total_deliveries'] > 0): ?>
                                    <div>
                                        <span class="text-success"><?php echo (int)$webhook['successful']; ?> ok</span> /
                                        <span class="text-danger"><?php echo (int)$webhook['failed']; ?> failed</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?php echo e(View::formatDate($webhook['last_attempt'])); ?></td>
                            <td class="text-right">
                                <div class="d-flex gap-1" style="justify-content:flex-end;">
                                    <a href="<?php echo e(url('webhooks/edit/' . (int)$webhook['id'])); ?>" class="btn btn-sm btn-outline">Edit</a>
                                    <form method="POST" action="<?php echo e(url('webhooks/toggle/' . (int)$webhook['id'])); ?>" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="return_to" value="webhooks/index">
                                        <button type="submit" class="btn btn-sm btn-secondary"><?php echo $webhook['status'] === 'active' ? 'Disable' : 'Enable'; ?></button>
                                    </form>
                                    <form method="POST" action="<?php echo e(url('webhooks/delete/' . (int)$webhook['id'])); ?>" class="inline-form"
                                          onsubmit="return confirm('Delete this webhook and its delivery log?');">
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
    </div>
<?php endif; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

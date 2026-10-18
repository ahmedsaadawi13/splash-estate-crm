<?php
// FILE: /app/views/webhooks/edit.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb">
            <a href="<?php echo e(url('webhooks/index')); ?>">Webhooks</a> /
            <a href="<?php echo e(url('webhooks/view/' . (int)$webhook['id'])); ?>"><?php echo e($webhook['name']); ?></a> / Edit
        </div>
        <h1>Edit Webhook</h1>
    </div>
</div>

<p class="text-muted">The signing secret is not editable here; use “Regenerate secret” on the webhook page.</p>

<?php
$formAction = 'webhooks/edit/' . (int)$webhook['id'];
$submitLabel = 'Save Changes';
$cancelPath = 'webhooks/view/' . (int)$webhook['id'];
require APP_PATH . '/views/webhooks/_form.php';
?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

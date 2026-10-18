<?php
// FILE: /app/views/webhooks/create.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('webhooks/index')); ?>">Webhooks</a> / New</div>
        <h1>Create Webhook</h1>
    </div>
</div>

<p class="text-muted">A signing secret is generated automatically and shown once after you save.</p>

<?php
$formAction = 'webhooks/create';
$submitLabel = 'Create Webhook';
$cancelPath = 'webhooks/index';
require APP_PATH . '/views/webhooks/_form.php';
?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

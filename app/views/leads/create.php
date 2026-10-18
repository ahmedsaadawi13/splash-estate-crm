<?php
// FILE: /app/views/leads/create.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('leads/index')); ?>">Leads</a> / New</div>
        <h1>Create Lead</h1>
    </div>
    <a href="<?php echo e(url('leads/index')); ?>" class="btn btn-secondary">Back to List</a>
</div>

<?php
$formAction = 'leads/create';
$submitLabel = 'Create Lead';
$showStatus = false;
$cancelUrl = 'leads/index';
require APP_PATH . '/views/leads/_form.php';
?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

<?php
// FILE: /app/views/clients/create.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('clients/index')); ?>">Clients</a> / New</div>
        <h1>Create Client</h1>
    </div>
    <a href="<?php echo e(url('clients/index')); ?>" class="btn btn-secondary">Back to List</a>
</div>

<?php
$formAction = 'clients/create';
$submitLabel = 'Create Client';
$showStatus = false;
$cancelUrl = 'clients/index';
require APP_PATH . '/views/clients/_form.php';
?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

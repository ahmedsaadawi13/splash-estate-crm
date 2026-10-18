<?php
// FILE: /app/views/clients/edit.php
require APP_PATH . '/views/layouts/header.php';
$clientName = trim($client['first_name'] . ' ' . (string)$client['last_name']);
?>

<div class="page-header">
    <div>
        <div class="breadcrumb">
            <a href="<?php echo e(url('clients/index')); ?>">Clients</a> /
            <a href="<?php echo e(url('clients/view/' . (int)$client['id'])); ?>"><?php echo e($clientName); ?></a> / Edit
        </div>
        <h1>Edit Client</h1>
    </div>
    <a href="<?php echo e(url('clients/view/' . (int)$client['id'])); ?>" class="btn btn-secondary">Back to Client</a>
</div>

<?php
$formAction = 'clients/edit/' . (int)$client['id'];
$submitLabel = 'Save Changes';
$showStatus = true;
$cancelUrl = 'clients/view/' . (int)$client['id'];
require APP_PATH . '/views/clients/_form.php';
?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

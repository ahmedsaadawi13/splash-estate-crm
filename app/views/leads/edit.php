<?php
// FILE: /app/views/leads/edit.php
require APP_PATH . '/views/layouts/header.php';
$leadName = trim($lead['first_name'] . ' ' . (string)$lead['last_name']);
?>

<div class="page-header">
    <div>
        <div class="breadcrumb">
            <a href="<?php echo e(url('leads/index')); ?>">Leads</a> /
            <a href="<?php echo e(url('leads/view/' . (int)$lead['id'])); ?>"><?php echo e($leadName); ?></a> / Edit
        </div>
        <h1>Edit Lead</h1>
    </div>
    <a href="<?php echo e(url('leads/view/' . (int)$lead['id'])); ?>" class="btn btn-secondary">Back to Lead</a>
</div>

<?php
$formAction = 'leads/edit/' . (int)$lead['id'];
$submitLabel = 'Save Changes';
$showStatus = true;
$cancelUrl = 'leads/view/' . (int)$lead['id'];
require APP_PATH . '/views/leads/_form.php';
?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

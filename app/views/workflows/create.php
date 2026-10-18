<?php
// FILE: /app/views/workflows/create.php
require APP_PATH . '/views/layouts/header.php';
?>
<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('workflows/index')); ?>">Workflows</a> / Create</div>
        <h1>Create Workflow</h1>
    </div>
</div>

<?php require APP_PATH . '/views/workflows/_form.php'; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

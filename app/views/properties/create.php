<?php
// FILE: /app/views/properties/create.php
require APP_PATH . '/views/layouts/header.php';
?>
<div class="breadcrumb"><a href="<?php echo e(url('properties/index')); ?>">Properties</a> &rsaquo; New</div>
<div class="page-header">
    <h1>Add Property</h1>
    <div class="header-actions">
        <a href="<?php echo e(url('properties/index')); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</div>

<form method="post" action="<?php echo e(url('properties/create')); ?>" novalidate>
    <?php require APP_PATH . '/views/properties/_form.php'; ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create Property</button>
        <a href="<?php echo e(url('properties/index')); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>
<?php require APP_PATH . '/views/layouts/footer.php'; ?>

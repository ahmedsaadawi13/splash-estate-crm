<?php
// FILE: /app/views/properties/edit.php
require APP_PATH . '/views/layouts/header.php';
$propertyId = (int)$property['id'];
?>
<div class="breadcrumb">
    <a href="<?php echo e(url('properties/index')); ?>">Properties</a> &rsaquo;
    <a href="<?php echo e(url('properties/view/' . $propertyId)); ?>"><?php echo e($property['title']); ?></a> &rsaquo; Edit
</div>
<div class="page-header">
    <h1>Edit Property</h1>
    <div class="header-actions">
        <a href="<?php echo e(url('properties/view/' . $propertyId)); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</div>

<form method="post" action="<?php echo e(url('properties/edit/' . $propertyId)); ?>" novalidate>
    <?php require APP_PATH . '/views/properties/_form.php'; ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="<?php echo e(url('properties/view/' . $propertyId)); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>
<?php require APP_PATH . '/views/layouts/footer.php'; ?>

<?php
// FILE: /app/views/deals/create.php
require APP_PATH . '/views/layouts/header.php';
?>
<div class="breadcrumb"><a href="<?php echo e(url('deals/index')); ?>">Deals</a> &rsaquo; New</div>
<div class="page-header">
    <h1>New Deal</h1>
    <div class="header-actions">
        <a href="<?php echo e(url('deals/index')); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</div>

<form method="post" action="<?php echo e(url('deals/create')); ?>" novalidate>
    <?php require APP_PATH . '/views/deals/_form.php'; ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create Deal</button>
        <a href="<?php echo e(url('deals/index')); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>
<?php require APP_PATH . '/views/layouts/footer.php'; ?>

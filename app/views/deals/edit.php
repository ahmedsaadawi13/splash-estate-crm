<?php
// FILE: /app/views/deals/edit.php
require APP_PATH . '/views/layouts/header.php';
$dealId = (int)$deal['id'];
?>
<div class="breadcrumb">
    <a href="<?php echo e(url('deals/index')); ?>">Deals</a> &rsaquo;
    <a href="<?php echo e(url('deals/view/' . $dealId)); ?>"><?php echo e($deal['title']); ?></a> &rsaquo; Edit
</div>
<div class="page-header">
    <h1>Edit Deal</h1>
    <div class="header-actions">
        <a href="<?php echo e(url('deals/view/' . $dealId)); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</div>

<form method="post" action="<?php echo e(url('deals/edit/' . $dealId)); ?>" novalidate>
    <?php require APP_PATH . '/views/deals/_form.php'; ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="<?php echo e(url('deals/view/' . $dealId)); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>
<?php require APP_PATH . '/views/layouts/footer.php'; ?>

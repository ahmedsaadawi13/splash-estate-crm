<?php
// FILE: /app/views/tasks/create.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('tasks/index')); ?>">Tasks</a> / New</div>
        <h1>Create Task</h1>
    </div>
    <a href="<?php echo e(url('tasks/index')); ?>" class="btn btn-secondary">Back to List</a>
</div>

<?php
$formAction = 'tasks/create';
$submitLabel = 'Create Task';
$cancelUrl = 'tasks/index';
require APP_PATH . '/views/tasks/_form.php';
?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

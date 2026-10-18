<?php
// FILE: /app/views/tasks/edit.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb">
            <a href="<?php echo e(url('tasks/index')); ?>">Tasks</a> /
            <a href="<?php echo e(url('tasks/view/' . (int)$task['id'])); ?>"><?php echo e($task['title']); ?></a> / Edit
        </div>
        <h1>Edit Task</h1>
    </div>
    <a href="<?php echo e(url('tasks/view/' . (int)$task['id'])); ?>" class="btn btn-secondary">Back to Task</a>
</div>

<?php
$formAction = 'tasks/edit/' . (int)$task['id'];
$submitLabel = 'Save Changes';
$cancelUrl = 'tasks/view/' . (int)$task['id'];
require APP_PATH . '/views/tasks/_form.php';
?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

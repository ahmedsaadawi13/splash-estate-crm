<?php
// FILE: /app/views/tasks/view.php
require APP_PATH . '/views/layouts/header.php';
$taskId = (int)$task['id'];
$open = in_array($task['status'], array('pending', 'in_progress'), true);
$priorityBadges = array('urgent' => 'badge-danger', 'high' => 'badge-danger', 'medium' => 'badge-warning', 'low' => 'badge-info');
$relatedPaths = array('lead' => 'leads', 'client' => 'clients', 'property' => 'properties', 'deal' => 'deals');
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('tasks/index')); ?>">Tasks</a> / <?php echo e($task['title']); ?></div>
        <h1>
            <?php echo e($task['title']); ?>
            <?php echo View::statusBadge($task['status'] === 'in_progress' ? 'in progress' : $task['status']); ?>
            <?php if (!empty($task['is_overdue'])): ?><span class="badge badge-danger">Overdue</span><?php endif; ?>
        </h1>
    </div>
    <div class="header-actions">
        <?php if ($open && can('tasks.edit')): ?>
            <form method="POST" action="<?php echo e(url('tasks/complete/' . $taskId)); ?>" class="inline-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="return_to" value="tasks/view/<?php echo $taskId; ?>">
                <button type="submit" class="btn btn-success">Mark Complete</button>
            </form>
        <?php endif; ?>
        <?php if (can('tasks.edit')): ?>
            <a href="<?php echo e(url('tasks/edit/' . $taskId)); ?>" class="btn btn-primary">Edit</a>
        <?php endif; ?>
        <?php if (can('tasks.delete')): ?>
            <form method="POST" action="<?php echo e(url('tasks/delete/' . $taskId)); ?>" class="inline-form"
                  onsubmit="return confirm('Delete this task?');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        <?php endif; ?>
        <a href="<?php echo e(url('tasks/index')); ?>" class="btn btn-secondary">Back to List</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <dl class="detail-list">
            <dt>Priority</dt>
            <dd><span class="badge <?php echo isset($priorityBadges[$task['priority']]) ? $priorityBadges[$task['priority']] : 'badge-secondary'; ?>"><?php echo e(ucfirst((string)$task['priority'])); ?></span></dd>
            <dt>Due</dt>
            <dd class="<?php echo !empty($task['is_overdue']) ? 'text-danger' : ''; ?>"><?php echo View::formatDate($task['due_date']); ?></dd>
            <dt>Assigned To</dt>
            <dd><?php echo e($task['assigned_to_name'] ?? 'Unassigned'); ?></dd>
            <dt>Created By</dt>
            <dd><?php echo e($task['created_by_name'] ?? '-'); ?></dd>
            <dt>Related To</dt>
            <dd>
                <?php if (!empty($task['related_to_type']) && isset($relatedPaths[$task['related_to_type']])): ?>
                    <?php echo e(ucfirst($task['related_to_type'])); ?>:
                    <a href="<?php echo e(url($relatedPaths[$task['related_to_type']] . '/view/' . (int)$task['related_to_id'])); ?>">
                        <?php echo e(trim((string)$task['related_name']) !== '' ? $task['related_name'] : '#' . (int)$task['related_to_id']); ?>
                    </a>
                <?php else: ?>-<?php endif; ?>
            </dd>
            <dt>Created</dt>
            <dd><?php echo View::formatDate($task['created_at']); ?></dd>
            <?php if (!empty($task['completed_at'])): ?>
                <dt>Completed</dt>
                <dd><?php echo View::formatDate($task['completed_at']); ?></dd>
            <?php endif; ?>
        </dl>

        <h3 class="mt-2">Description</h3>
        <?php if (!empty($task['description'])): ?>
            <p><?php echo nl2br(e($task['description'])); ?></p>
        <?php else: ?>
            <p class="text-muted">No description.</p>
        <?php endif; ?>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

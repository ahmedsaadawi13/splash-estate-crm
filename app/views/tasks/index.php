<?php
// FILE: /app/views/tasks/index.php
require APP_PATH . '/views/layouts/header.php';
$filters = isset($filters) && is_array($filters) ? $filters : array();
$f = function ($key) use ($filters) { return isset($filters[$key]) ? (string)$filters[$key] : ''; };
$priorityBadges = array('urgent' => 'badge-danger', 'high' => 'badge-danger', 'medium' => 'badge-warning', 'low' => 'badge-info');
$statusLabels = array('open' => 'Open (pending + in progress)', 'pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled');
$relatedPaths = array('lead' => 'leads', 'client' => 'clients', 'property' => 'properties', 'deal' => 'deals');
?>

<div class="page-header">
    <h1>Tasks <small class="text-muted">(<?php echo (int)$totalTasks; ?>)</small></h1>
    <div class="header-actions">
        <a href="<?php echo e(url('tasks/index') . '?assigned_to=me&status=open'); ?>" class="btn btn-outline">My open tasks</a>
        <a href="<?php echo e(url('tasks/index') . '?overdue=1'); ?>" class="btn btn-outline">Overdue</a>
        <?php if (can('tasks.create')): ?>
            <a href="<?php echo e(url('tasks/create')); ?>" class="btn btn-primary">Create Task</a>
        <?php endif; ?>
    </div>
</div>

<div class="filters">
    <form method="GET" action="<?php echo e(url('tasks/index')); ?>" class="filter-form">
        <input type="text" name="search" placeholder="Search tasks..." value="<?php echo e($f('search')); ?>" class="form-control">
        <select name="status" class="form-control">
            <option value="">All Statuses</option>
            <?php foreach ($statusLabels as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php echo $f('status') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="priority" class="form-control">
            <option value="">All Priorities</option>
            <?php foreach (array('urgent' => 'Urgent', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low') as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php echo $f('priority') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="assigned_to" class="form-control">
            <option value="">All Assignees</option>
            <?php foreach ($agents as $agent): ?>
                <option value="<?php echo (int)$agent['id']; ?>" <?php echo $f('assigned_to') === (string)$agent['id'] ? 'selected' : ''; ?>>
                    <?php echo e($agent['first_name'] . ' ' . $agent['last_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label class="d-flex align-center gap-1"><input type="checkbox" name="overdue" value="1" <?php echo $f('overdue') !== '' ? 'checked' : ''; ?>> Overdue only</label>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <a href="<?php echo e(url('tasks/index')); ?>" class="btn btn-link">Clear</a>
    </form>
</div>

<?php if (!empty($tasks)): ?>
<div class="table-responsive">
<table class="table">
    <thead>
        <tr>
            <th>Task</th>
            <th>Related To</th>
            <th>Due</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Assigned To</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($tasks as $task): ?>
        <?php $taskId = (int)$task['id']; $open = in_array($task['status'], array('pending', 'in_progress'), true); ?>
        <tr>
            <td><a href="<?php echo e(url('tasks/view/' . $taskId)); ?>"><?php echo e($task['title']); ?></a></td>
            <td>
                <?php if (!empty($task['related_to_type']) && isset($relatedPaths[$task['related_to_type']])): ?>
                    <small class="text-muted"><?php echo e(ucfirst($task['related_to_type'])); ?>:</small>
                    <a href="<?php echo e(url($relatedPaths[$task['related_to_type']] . '/view/' . (int)$task['related_to_id'])); ?>">
                        <?php echo e(trim((string)$task['related_name']) !== '' ? $task['related_name'] : '#' . (int)$task['related_to_id']); ?>
                    </a>
                <?php else: ?>-<?php endif; ?>
            </td>
            <td class="<?php echo !empty($task['is_overdue']) ? 'text-danger' : ''; ?>">
                <?php echo View::formatDate($task['due_date']); ?>
                <?php if (!empty($task['is_overdue'])): ?><span class="badge badge-danger">Overdue</span><?php endif; ?>
            </td>
            <td><span class="badge <?php echo isset($priorityBadges[$task['priority']]) ? $priorityBadges[$task['priority']] : 'badge-secondary'; ?>"><?php echo e(ucfirst((string)$task['priority'])); ?></span></td>
            <td><?php echo View::statusBadge($task['status'] === 'in_progress' ? 'in progress' : $task['status']); ?></td>
            <td><?php echo e($task['assigned_to_name'] ?? 'Unassigned'); ?></td>
            <td class="d-flex gap-1">
                <?php if ($open && can('tasks.edit')): ?>
                    <form method="POST" action="<?php echo e(url('tasks/complete/' . $taskId)); ?>" class="inline-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="return_to" value="tasks/index">
                        <button type="submit" class="btn btn-sm btn-success">Complete</button>
                    </form>
                <?php endif; ?>
                <?php if (can('tasks.edit')): ?>
                    <a href="<?php echo e(url('tasks/edit/' . $taskId)); ?>" class="btn btn-sm btn-outline">Edit</a>
                <?php endif; ?>
                <?php if (can('tasks.delete')): ?>
                    <form method="POST" action="<?php echo e(url('tasks/delete/' . $taskId)); ?>" class="inline-form"
                          onsubmit="return confirm('Delete this task?');">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php
$paginationPath = 'tasks/index';
$paginationQuery = $filters;
require APP_PATH . '/views/leads/_pagination.php';
?>

<?php else: ?>
<div class="empty-state"><p>No tasks found.</p></div>
<?php endif; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

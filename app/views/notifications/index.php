<?php
// FILE: /app/views/notifications/index.php
require APP_PATH . '/views/layouts/header.php';

$typeBadges = array(
    'info' => 'info', 'success' => 'success', 'warning' => 'danger', 'task' => 'primary',
    'task_reminder' => 'primary', 'lead' => 'primary', 'deal' => 'success', 'workflow' => 'secondary'
);
// Badge labels
$typeLabels = array(
    'info' => 'Info', 'success' => 'Success', 'warning' => 'Alert', 'task' => 'Task',
    'task_reminder' => 'Reminder', 'lead' => 'Lead', 'deal' => 'Deal', 'workflow' => 'Workflow'
);
$pagerQuery = $unreadOnly ? array('filter' => 'unread') : array();
?>
<div class="page-header">
    <div>
        <h1>Notifications</h1>
        <p class="text-muted small"><?php echo (int)$unreadCount; ?> unread</p>
    </div>
    <div class="header-actions">
        <?php if ($unreadOnly): ?>
            <a href="<?php echo e(url('notifications/index')); ?>" class="btn btn-outline">Show all</a>
        <?php else: ?>
            <a href="<?php echo e(url('notifications/index') . '?filter=unread'); ?>" class="btn btn-outline">Unread only</a>
        <?php endif; ?>
        <?php if ($unreadCount > 0): ?>
            <form method="POST" action="<?php echo e(url('notifications/markAllRead')); ?>" class="inline-form">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-secondary">Mark all as read</button>
            </form>
        <?php endif; ?>
        <form method="POST" action="<?php echo e(url('notifications/clearRead')); ?>" class="inline-form" onsubmit="return confirm('Delete all read notifications?');">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-outline">Delete read</button>
        </form>
    </div>
</div>

<div class="card">
    <?php if (empty($notifications)): ?>
        <div class="empty-state">
            <h3><?php echo $unreadOnly ? 'No unread notifications' : 'No notifications yet'; ?></h3>
            <p>Task reminders, workflow alerts and other updates will appear here.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <tbody>
                    <?php foreach ($notifications as $n): ?>
                        <?php
                        $nid = (int)$n['id'];
                        $type = (string)($n['type'] ?? 'info');
                        $badge = isset($typeBadges[$type]) ? $typeBadges[$type] : 'secondary';
                        $unread = (int)$n['is_read'] === 0;
                        ?>
                        <tr<?php echo $unread ? ' style="background:#f4f9ff;"' : ''; ?>>
                            <td style="width:110px;"><span class="badge badge-<?php echo e($badge); ?>"><?php echo e(isset($typeLabels[$type]) ? $typeLabels[$type] : ucwords(str_replace('_', ' ', $type))); ?></span></td>
                            <td>
                                <div><?php echo $unread ? '<strong>' . e($n['title']) . '</strong>' : e($n['title']); ?></div>
                                <?php if (!empty($n['message'])): ?>
                                    <div class="small text-muted"><?php echo nl2br(e($n['message'])); ?></div>
                                <?php endif; ?>
                                <div class="small text-muted mt-1"><?php echo e(View::formatDate($n['created_at'] ?? '')); ?></div>
                            </td>
                            <td class="text-right" style="white-space:nowrap;">
                                <?php if (!empty($n['link'])): ?>
                                    <form method="POST" action="<?php echo e(url('notifications/markRead/' . $nid)); ?>" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="open" value="1">
                                        <button type="submit" class="btn btn-sm btn-primary">Open</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($unread): ?>
                                    <form method="POST" action="<?php echo e(url('notifications/markRead/' . $nid)); ?>" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-sm btn-outline">Mark read</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" action="<?php echo e(url('notifications/delete/' . $nid)); ?>" class="inline-form">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="<?php echo e(url('notifications/index') . '?' . http_build_query(array_merge($pagerQuery, array('page' => $i)))); ?>" class="page-link<?php echo $i === (int)$page ? ' active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

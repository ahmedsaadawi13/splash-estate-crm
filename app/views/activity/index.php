<?php
// FILE: /app/views/activity/index.php
require APP_PATH . '/views/layouts/header.php';

$pageUrl = function ($p) use ($baseUrl, $query) {
    $q = $query;
    $q['page'] = $p;
    return $baseUrl . '?' . http_build_query($q);
};
$label = function ($value) {
    return ucfirst(str_replace('_', ' ', (string)$value));
};
?>

<div class="page-header">
    <div>
        <h1>Activity Log</h1>
        <p class="text-muted"><?php echo (int)$total; ?> event(s)<?php echo $isPlatform ? ' across all organizations' : ''; ?>.</p>
    </div>
</div>

<div class="filters">
    <form method="GET" action="<?php echo e($baseUrl); ?>" class="filter-form">
        <select name="user_id" class="form-control" aria-label="User">
            <option value="">All users</option>
            <?php foreach ($users as $u): ?>
                <option value="<?php echo (int)$u['id']; ?>" <?php echo $filters['user_id'] === (string)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="action" class="form-control" aria-label="Action">
            <option value="">All actions</option>
            <?php foreach ($actions as $a): ?>
                <option value="<?php echo e($a); ?>" <?php echo $filters['action'] === $a ? 'selected' : ''; ?>><?php echo e($label($a)); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="entity_type" class="form-control" aria-label="Entity type">
            <option value="">All types</option>
            <?php foreach ($entityTypes as $t): ?>
                <option value="<?php echo e($t); ?>" <?php echo $filters['entity_type'] === $t ? 'selected' : ''; ?>><?php echo e($label($t)); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" class="form-control" aria-label="From" value="<?php echo e($filters['date_from']); ?>">
        <input type="date" name="date_to" class="form-control" aria-label="To" value="<?php echo e($filters['date_to']); ?>">
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if (!empty($query)): ?><a href="<?php echo e($baseUrl); ?>" class="btn btn-link">Reset</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($logs)): ?>
            <div class="empty-state"><p>No activity matches these filters.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <?php if ($isPlatform): ?><th>Organization</th><?php endif; ?>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>IP address</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo e(View::formatDate($log['created_at'] ?? '')); ?></td>
                            <?php if ($isPlatform): ?><td><?php echo e($log['tenant_name'] ?? '-'); ?></td><?php endif; ?>
                            <td>
                                <?php if ($log['first_name'] !== null): ?>
                                    <?php echo e($log['first_name'] . ' ' . $log['last_name']); ?>
                                <?php elseif ($log['user_id'] !== null): ?>
                                    <span class="text-muted">User #<?php echo (int)$log['user_id']; ?> (deleted)</span>
                                <?php else: ?>
                                    <span class="text-muted">System</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-secondary"><?php echo e($label($log['action'])); ?></span></td>
                            <td>
                                <?php if ($log['entity_type'] !== null && $log['entity_type'] !== ''): ?>
                                    <?php $text = $label($log['entity_type']) . ($log['entity_id'] !== null ? ' #' . (int)$log['entity_id'] : ''); ?>
                                    <?php if ($log['link'] !== null): ?>
                                        <a href="<?php echo e($log['link']); ?>"><?php echo e($text); ?></a>
                                    <?php else: ?>
                                        <?php echo e($text); ?>
                                    <?php endif; ?>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td class="small text-muted" title="<?php echo e($log['user_agent'] ?? ''); ?>"><?php echo e($log['ip_address'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <div class="pagination">
                <?php if ($page > 1): ?><a class="page-link" href="<?php echo e($pageUrl($page - 1)); ?>">&laquo; Previous</a><?php endif; ?>
                <?php for ($i = max(1, $page - 4); $i <= min($totalPages, $page + 4); $i++): ?>
                    <a class="page-link<?php echo $i === $page ? ' active' : ''; ?>" href="<?php echo e($pageUrl($i)); ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><a class="page-link" href="<?php echo e($pageUrl($page + 1)); ?>">Next &raquo;</a><?php endif; ?>
            </div>
            <p class="small text-muted">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></p>
        </div>
    <?php endif; ?>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

<?php
// FILE: /app/views/users/index.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <h1>Team</h1>
        <p class="text-muted">Manage the people who can sign in<?php echo $isPlatform ? ' across all organizations' : ' to your organization'; ?>.</p>
    </div>
    <div class="header-actions">
        <?php if (can('roles.manage')): ?>
            <a href="<?php echo e(url('roles/index')); ?>" class="btn btn-secondary">Roles</a>
        <?php endif; ?>
        <?php if ($quotaOk): ?>
            <a href="<?php echo e(url('users/create')); ?>" class="btn btn-primary">+ Invite Member</a>
        <?php else: ?>
            <span class="text-warning small">User limit of your plan reached.</span>
            <?php if (can('subscription.view')): ?><a href="<?php echo e(url('subscription/index')); ?>" class="btn btn-outline">Upgrade</a><?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="filters">
    <form method="GET" action="<?php echo e(url('users/index')); ?>" class="filter-form">
        <input type="text" name="q" class="form-control" placeholder="Name or email" value="<?php echo e($search); ?>">
        <select name="status" class="form-control">
            <option value="">All statuses</option>
            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
    </form>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($users)): ?>
            <div class="empty-state"><p>No users match.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <?php if ($isPlatform): ?><th>Organization</th><?php endif; ?>
                            <th>Roles</th>
                            <th>Status</th>
                            <th>Last login</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $uid = (int)$user['id']; $roles = isset($rolesByUser[$uid]) ? $rolesByUser[$uid] : array(); ?>
                        <tr>
                            <td>
                                <strong><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                <?php if ($uid === $currentUserId): ?><span class="badge badge-info">You</span><?php endif; ?>
                                <?php if (!empty($user['phone'])): ?><div class="small text-muted"><?php echo e($user['phone']); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo e($user['email']); ?></td>
                            <?php if ($isPlatform): ?><td><?php echo e($user['tenant_name'] ?? 'Platform'); ?></td><?php endif; ?>
                            <td>
                                <?php if (empty($roles)): ?>
                                    <span class="badge badge-secondary"><?php echo e(ucwords(str_replace('_', ' ', $user['role'] ?? ''))); ?></span>
                                    <span class="small text-muted">(default)</span>
                                <?php else: ?>
                                    <?php foreach ($roles as $role): ?>
                                        <span class="badge <?php echo $role['tenant_id'] === null ? 'badge-primary' : 'badge-info'; ?>"><?php echo e($role['name']); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo View::statusBadge($user['status'] ?? 'inactive'); ?></td>
                            <td><?php echo e(View::formatDate($user['last_login'] ?? '')); ?></td>
                            <td class="text-right">
                                <?php if ($user['tenant_id'] !== null): ?>
                                    <div class="d-flex gap-1" style="justify-content:flex-end">
                                        <a href="<?php echo e(url('users/edit/' . $uid)); ?>" class="btn btn-sm btn-secondary">Edit</a>
                                        <?php if ($uid !== $currentUserId): ?>
                                            <form method="POST" action="<?php echo e(url('users/toggle-status/' . $uid)); ?>" class="inline-form"
                                                  onsubmit="return confirm('<?php echo $user['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> this user?');">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" class="btn btn-sm <?php echo $user['status'] === 'active' ? 'btn-warning' : 'btn-success'; ?>">
                                                    <?php echo $user['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

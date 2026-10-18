<?php
// FILE: /app/views/roles/index.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <h1>Roles &amp; Permissions</h1>
        <p class="text-muted">System roles are shared by every organization and are read-only. Create custom roles to grant a specific set of permissions.</p>
    </div>
    <div class="header-actions">
        <?php if (can('users.manage')): ?>
            <a href="<?php echo e(url('users/index')); ?>" class="btn btn-secondary">Team</a>
        <?php endif; ?>
        <?php if (!$isPlatform || !empty($_SESSION['tenant_id'])): ?>
            <a href="<?php echo e(url('roles/create')); ?>" class="btn btn-primary">+ New Role</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Type</th>
                        <?php if ($isPlatform): ?><th>Organization</th><?php endif; ?>
                        <th>Permissions</th>
                        <th>Users</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roles as $role): ?>
                    <?php $custom = empty($role['is_system']) && $role['tenant_id'] !== null; ?>
                    <tr>
                        <td>
                            <a href="<?php echo e(url('roles/view/' . (int)$role['id'])); ?>"><strong><?php echo e($role['name']); ?></strong></a>
                            <?php if (!empty($role['description'])): ?><div class="small text-muted"><?php echo e($role['description']); ?></div><?php endif; ?>
                        </td>
                        <td><?php echo $custom ? '<span class="badge badge-info">Custom</span>' : '<span class="badge badge-secondary">System</span>'; ?></td>
                        <?php if ($isPlatform): ?><td><?php echo e($role['tenant_name'] ?? '-'); ?></td><?php endif; ?>
                        <td><?php echo (int)$role['permission_count']; ?></td>
                        <td><?php echo (int)$role['user_count']; ?></td>
                        <td class="text-right">
                            <a href="<?php echo e(url('roles/view/' . (int)$role['id'])); ?>" class="btn btn-sm btn-secondary">View</a>
                            <?php if ($custom): ?>
                                <a href="<?php echo e(url('roles/edit/' . (int)$role['id'])); ?>" class="btn btn-sm btn-outline">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

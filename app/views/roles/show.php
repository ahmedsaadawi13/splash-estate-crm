<?php
// FILE: /app/views/roles/show.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('roles/index')); ?>">Roles</a> / <?php echo e($role['name']); ?></div>
        <h1>
            <?php echo e($role['name']); ?>
            <?php echo $editable ? '<span class="badge badge-info">Custom</span>' : '<span class="badge badge-secondary">System &middot; read-only</span>'; ?>
        </h1>
        <?php if (!empty($role['description'])): ?><p class="text-muted"><?php echo e($role['description']); ?></p><?php endif; ?>
    </div>
    <?php if ($editable): ?>
        <div class="header-actions">
            <a href="<?php echo e(url('roles/edit/' . (int)$role['id'])); ?>" class="btn btn-primary">Edit</a>
            <form method="POST" action="<?php echo e(url('roles/delete/' . (int)$role['id'])); ?>" class="inline-form"
                  onsubmit="return confirm('Delete this role?');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="layout-main-side">
    <div class="card">
        <div class="card-header"><h3>Permissions (<?php echo count($granted); ?>)</h3></div>
        <div class="card-body">
            <?php foreach ($groupedPermissions as $module => $permissions): ?>
                <h4 class="mt-2"><?php echo e(ucfirst($module)); ?></h4>
                <ul style="list-style:none;padding-left:0">
                    <?php foreach ($permissions as $permission): ?>
                        <?php $has = in_array((int)$permission['id'], $granted, true); ?>
                        <li class="<?php echo $has ? '' : 'text-muted'; ?>">
                            <?php echo $has ? '<span class="text-success">&#10003;</span>' : '<span>&#10007;</span>'; ?>
                            <?php echo e($permission['name']); ?>
                            <code class="small"><?php echo e($permission['slug']); ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Users with this role (<?php echo count($users); ?>)</h3></div>
        <div class="card-body">
            <?php if (empty($users)): ?>
                <p class="text-muted">Nobody has this role yet.</p>
            <?php else: ?>
                <ul style="list-style:none;padding-left:0">
                    <?php foreach ($users as $user): ?>
                        <li class="mb-1">
                            <?php if (can('users.manage')): ?>
                                <a href="<?php echo e(url('users/edit/' . (int)$user['id'])); ?>"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></a>
                            <?php else: ?>
                                <?php echo e($user['first_name'] . ' ' . $user['last_name']); ?>
                            <?php endif; ?>
                            <div class="small text-muted"><?php echo e($user['email']); ?> &middot; <?php echo e($user['status']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="form-help">Assign roles from the team page.</p>
        </div>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

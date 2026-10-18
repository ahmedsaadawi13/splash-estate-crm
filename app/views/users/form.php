<?php
// FILE: /app/views/users/form.php
require APP_PATH . '/views/layouts/header.php';

$isEdit = $user !== null;
$action = $isEdit ? url('users/edit/' . (int)$user['id']) : url('users/create');
$selectedRoles = array_map('intval', $form['roles']);
$err = function ($key) use ($errors) {
    return isset($errors[$key]) ? '<div class="error">' . e($errors[$key]) . '</div>' : '';
};
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('users/index')); ?>">Team</a> / <?php echo $isEdit ? e($user['first_name'] . ' ' . $user['last_name']) : 'Invite'; ?></div>
        <h1><?php echo e($title); ?></h1>
    </div>
    <?php if ($isEdit): ?>
        <div class="header-actions">
            <form method="POST" action="<?php echo e(url('users/send-reset/' . (int)$user['id'])); ?>" class="inline-form"
                  onsubmit="return confirm('Email this user a link to set a new password?');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-outline">Send password setup link</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($errors['general'])): ?>
    <div class="alert alert-error"><?php echo e($errors['general']); ?></div>
<?php endif; ?>

<form method="POST" action="<?php echo e($action); ?>">
    <?php echo csrf_field(); ?>
    <div class="card">
        <div class="card-header"><h3>Profile</h3></div>
        <div class="card-body">
            <?php if (!empty($tenants)): ?>
                <div class="form-group">
                    <label for="tenant_id">Organization <span class="required">*</span></label>
                    <select id="tenant_id" name="tenant_id" class="form-control" required>
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($tenants as $tenant): ?>
                            <option value="<?php echo (int)$tenant['id']; ?>" <?php echo (int)$form['tenant_id'] === (int)$tenant['id'] ? 'selected' : ''; ?>><?php echo e($tenant['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-help">Only system roles are listed below; custom roles can be assigned after the user is created.</div>
                    <?php echo $err('tenant_id'); ?>
                </div>
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First name <span class="required">*</span></label>
                    <input type="text" id="first_name" name="first_name" class="form-control" maxlength="100" required value="<?php echo e($form['first_name']); ?>">
                    <?php echo $err('first_name'); ?>
                </div>
                <div class="form-group">
                    <label for="last_name">Last name <span class="required">*</span></label>
                    <input type="text" id="last_name" name="last_name" class="form-control" maxlength="100" required value="<?php echo e($form['last_name']); ?>">
                    <?php echo $err('last_name'); ?>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email <span class="required">*</span></label>
                    <input type="email" id="email" name="email" class="form-control" maxlength="255" required value="<?php echo e($form['email']); ?>">
                    <?php echo $err('email'); ?>
                </div>
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="text" id="phone" name="phone" class="form-control" maxlength="50" value="<?php echo e($form['phone']); ?>">
                    <?php echo $err('phone'); ?>
                </div>
            </div>
            <?php if ($isEdit): ?>
                <p class="small text-muted">
                    Status: <?php echo View::statusBadge($user['status'] ?? 'inactive'); ?>
                    &middot; Last login: <?php echo e(View::formatDate($user['last_login'] ?? '')); ?>
                    &middot; Member since: <?php echo e(View::formatDate($user['created_at'] ?? '', 'Y-m-d')); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header"><h3>Roles</h3></div>
        <div class="card-body">
            <?php echo $err('roles'); ?>
            <?php if ($canAssign): ?>
                <input type="hidden" name="roles_submitted" value="1">
                <?php foreach ($roleOptions as $role): ?>
                    <?php $rid = (int)$role['id']; ?>
                    <label style="display:block;font-weight:normal" class="<?php echo $role['assignable'] ? '' : 'text-muted'; ?>">
                        <input type="checkbox" name="roles[]" value="<?php echo $rid; ?>"
                            <?php echo in_array($rid, $selectedRoles, true) ? 'checked' : ''; ?>
                            <?php echo $role['assignable'] ? '' : 'disabled'; ?>>
                        <strong><?php echo e($role['name']); ?></strong>
                        <?php echo $role['tenant_id'] === null ? '<span class="badge badge-secondary">System</span>' : '<span class="badge badge-info">Custom</span>'; ?>
                        <?php if (!empty($role['description'])): ?><span class="small text-muted">&mdash; <?php echo e($role['description']); ?></span><?php endif; ?>
                        <?php if (!$role['assignable']): ?><span class="small">(grants permissions you do not have)</span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <p class="form-help">Users get the combined permissions of all their roles. Giving the Tenant Admin role also marks the account as an organization admin.</p>
                <?php if ($isSelf): ?><p class="form-help text-warning">You are editing your own roles.</p><?php endif; ?>
            <?php else: ?>
                <?php if ($isEdit): ?>
                    <?php $names = array(); foreach ($roleOptions as $role) { if (in_array((int)$role['id'], $selectedRoles, true)) { $names[] = $role['name']; } } ?>
                    <p><?php echo !empty($names) ? e(implode(', ', $names)) : e(ucwords(str_replace('_', ' ', $user['role'] ?? ''))) . ' (default)'; ?></p>
                <?php else: ?>
                    <p>New members get the <strong>Agent</strong> role.</p>
                <?php endif; ?>
                <p class="form-help">Changing roles requires the "Manage roles" permission<?php echo can('roles.manage') ? ' and that you hold every permission of this user\'s current roles' : ''; ?>.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isEdit): ?>
        <div class="card mt-2">
            <div class="card-header"><h3>Sign-in</h3></div>
            <div class="card-body">
                <label style="display:block;font-weight:normal">
                    <input type="radio" name="password_mode" value="reset_link" <?php echo ($form['password_mode'] ?? 'reset_link') !== 'temp_password' ? 'checked' : ''; ?>>
                    Email an invitation link so they choose their own password (valid 72 hours)
                </label>
                <label style="display:block;font-weight:normal">
                    <input type="radio" name="password_mode" value="temp_password" <?php echo ($form['password_mode'] ?? '') === 'temp_password' ? 'checked' : ''; ?>>
                    Generate a temporary password (emailed and shown to you once)
                </label>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-actions mt-2">
        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Save Changes' : 'Invite Member'; ?></button>
        <a href="<?php echo e(url('users/index')); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

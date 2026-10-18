<?php
// FILE: /app/views/roles/form.php
require APP_PATH . '/views/layouts/header.php';

$isEdit = $role !== null;
$action = $isEdit ? url('roles/edit/' . (int)$role['id']) : url('roles/create');
$selected = array_map('intval', $form['permissions']);
?>

<div class="page-header">
    <div>
        <div class="breadcrumb">
            <a href="<?php echo e(url('roles/index')); ?>">Roles</a> /
            <?php if ($isEdit): ?><a href="<?php echo e(url('roles/view/' . (int)$role['id'])); ?>"><?php echo e($role['name']); ?></a> / Edit<?php else: ?>New role<?php endif; ?>
        </div>
        <h1><?php echo e($title); ?></h1>
    </div>
</div>

<?php if (isset($errors['general'])): ?>
    <div class="alert alert-error"><?php echo e($errors['general']); ?></div>
<?php endif; ?>

<form method="POST" action="<?php echo e($action); ?>">
    <?php echo csrf_field(); ?>
    <div class="card">
        <div class="card-body">
            <div class="form-group">
                <label for="name">Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" class="form-control" maxlength="100" required value="<?php echo e($form['name']); ?>">
                <?php if (isset($errors['name'])): ?><div class="error"><?php echo e($errors['name']); ?></div><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="2"><?php echo e($form['description']); ?></textarea>
            </div>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header">
            <h3>Permissions</h3>
            <?php if ($grantable !== null): ?>
                <span class="small text-muted">You can only grant permissions you hold yourself.</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (isset($errors['permissions'])): ?><div class="error mb-1"><?php echo e($errors['permissions']); ?></div><?php endif; ?>
            <div class="grid-3">
                <?php foreach ($groupedPermissions as $module => $permissions): ?>
                    <fieldset style="border:1px solid #e1e4e8;border-radius:4px;padding:0.5rem 1rem">
                        <legend><strong><?php echo e(ucfirst($module)); ?></strong></legend>
                        <?php foreach ($permissions as $permission): ?>
                            <?php
                            $pid = (int)$permission['id'];
                            $allowed = $grantable === null || in_array($pid, $grantable, true);
                            ?>
                            <label style="display:block;font-weight:normal" class="<?php echo $allowed ? '' : 'text-muted'; ?>">
                                <input type="checkbox" name="permissions[]" value="<?php echo $pid; ?>"
                                    <?php echo in_array($pid, $selected, true) ? 'checked' : ''; ?>
                                    <?php echo $allowed ? '' : 'disabled'; ?>>
                                <?php echo e($permission['name']); ?>
                                <code class="small"><?php echo e($permission['slug']); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="form-actions mt-2">
        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Save Role' : 'Create Role'; ?></button>
        <a href="<?php echo e(url($isEdit ? 'roles/view/' . (int)$role['id'] : 'roles/index')); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

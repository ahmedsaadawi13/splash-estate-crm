<?php
// FILE: /app/views/scoring/form.php
require APP_PATH . '/views/layouts/header.php';

$isEdit = $ruleId !== null;
$action = $isEdit ? url('scoring/edit/' . (int)$ruleId) : url('scoring/create');
$val = function ($key) use ($formData) {
    return isset($formData[$key]) && $formData[$key] !== null ? (string)$formData[$key] : '';
};
$err = function ($key) use ($errors) {
    return isset($errors[$key]) ? '<div class="error">' . e($errors[$key]) . '</div>' : '';
};
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('scoring/index')); ?>">Lead Scoring</a> / <?php echo $isEdit ? 'Edit rule' : 'New rule'; ?></div>
        <h1><?php echo e($title); ?></h1>
    </div>
</div>

<?php if (isset($errors['general'])): ?>
    <div class="alert alert-error"><?php echo e($errors['general']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="<?php echo e($action); ?>">
            <?php echo csrf_field(); ?>

            <div class="form-row">
                <div class="form-group">
                    <label for="name">Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" class="form-control" maxlength="255" required value="<?php echo e($val('name')); ?>">
                    <?php echo $err('name'); ?>
                </div>
                <div class="form-group">
                    <label for="rule_type">Rule type <span class="required">*</span></label>
                    <select id="rule_type" name="rule_type" class="form-control">
                        <?php foreach ($ruleTypes as $key => $info): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $val('rule_type') === $key ? 'selected' : ''; ?>><?php echo e($info['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $err('rule_type'); ?>
                </div>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="2"><?php echo e($val('description')); ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="field_name">Lead field <span class="required">*</span></label>
                    <select id="field_name" name="field_name" class="form-control">
                        <?php foreach ($fields as $key => $label): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $val('field_name') === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $err('field_name'); ?>
                </div>
                <div class="form-group">
                    <label for="operator">Operator <span class="required">*</span></label>
                    <select id="operator" name="operator" class="form-control">
                        <?php foreach ($operators as $key => $label): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $val('operator') === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $err('operator'); ?>
                </div>
                <div class="form-group">
                    <label for="field_value">Value</label>
                    <input type="text" id="field_value" name="field_value" class="form-control" maxlength="255" value="<?php echo e($val('field_value')); ?>">
                    <div class="form-help">Not used for "is empty" / "is not empty". Lists are comma-separated; text comparisons ignore case.</div>
                    <?php echo $err('field_value'); ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="points">Points <span class="required">*</span></label>
                    <input type="number" id="points" name="points" class="form-control" min="-100" max="100" step="1" required value="<?php echo e($val('points')); ?>">
                    <div class="form-help">Negative points lower the score (the total is clamped to 0&ndash;100).</div>
                    <?php echo $err('points'); ?>
                </div>
                <div class="form-group">
                    <label for="execution_order">Order</label>
                    <input type="number" id="execution_order" name="execution_order" class="form-control" step="1" value="<?php echo e($val('execution_order')); ?>">
                    <?php echo $err('execution_order'); ?>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="active" <?php echo $val('status') !== 'inactive' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $val('status') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Save Rule' : 'Create Rule'; ?></button>
                <a href="<?php echo e(url('scoring/index')); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

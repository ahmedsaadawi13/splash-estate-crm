<?php
// FILE: /app/views/clients/_form.php
// Shared client form. Expects: $formData, $errors, $formAction, $submitLabel, $showStatus, $cancelUrl
$fd = isset($formData) && is_array($formData) ? $formData : array();
$errors = isset($errors) && is_array($errors) ? $errors : array();
$val = function ($key, $default = '') use ($fd) {
    return isset($fd[$key]) && $fd[$key] !== null ? (string)$fd[$key] : $default;
};
$err = function ($key) use ($errors) {
    return isset($errors[$key]) ? '<span class="error">' . e($errors[$key]) . '</span>' : '';
};
$typeLabels = array('buyer' => 'Buyer', 'seller' => 'Seller', 'landlord' => 'Landlord', 'tenant' => 'Tenant');
$text = function ($name, $label, $max, $type = 'text', $required = false) use ($val, $err) {
    echo '<div class="form-group"><label for="' . $name . '">' . e($label) . ($required ? ' <span class="required">*</span>' : '') . '</label>'
       . '<input type="' . $type . '" id="' . $name . '" name="' . $name . '" class="form-control" maxlength="' . (int)$max . '"'
       . ($required ? ' required' : '') . ' value="' . e($val($name)) . '">' . $err($name) . '</div>';
};
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="POST" action="<?php echo e(url($formAction)); ?>" class="card">
    <?php echo csrf_field(); ?>
    <div class="card-body">
        <div class="form-row">
            <?php $text('first_name', 'First Name', 100, 'text', true); ?>
            <?php $text('last_name', 'Last Name', 100); ?>
        </div>
        <div class="form-row">
            <?php $text('email', 'Email', 255, 'email'); ?>
            <?php $text('phone', 'Phone', 50, 'tel'); ?>
            <?php $text('phone_secondary', 'Secondary Phone', 50, 'tel'); ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="client_type">Client Type <span class="required">*</span></label>
                <select id="client_type" name="client_type" class="form-control" required>
                    <?php foreach ($typeLabels as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $val('client_type', 'buyer') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('client_type'); ?>
            </div>
            <?php if (!empty($showStatus)): ?>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="active" <?php echo $val('status', 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $val('status') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <?php echo $err('status'); ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="address">Address</label>
            <textarea id="address" name="address" class="form-control" rows="2"><?php echo e($val('address')); ?></textarea>
            <?php echo $err('address'); ?>
        </div>
        <div class="form-row">
            <?php $text('city', 'City', 100); ?>
            <?php $text('state', 'State', 100); ?>
            <?php $text('zip', 'ZIP', 20); ?>
        </div>
        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" class="form-control" rows="4"><?php echo e($val('notes')); ?></textarea>
            <?php echo $err('notes'); ?>
        </div>
    </div>
    <div class="card-footer form-actions">
        <button type="submit" class="btn btn-primary"><?php echo e($submitLabel); ?></button>
        <a href="<?php echo e(url($cancelUrl)); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>

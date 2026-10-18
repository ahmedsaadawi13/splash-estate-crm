<?php
// FILE: /app/views/deals/_form.php
// Shared deal form fields. Expects: $formData, $errors, $clients, $properties, $agents, $stages
$f = isset($formData) && is_array($formData) ? $formData : array();
$errors = isset($errors) && is_array($errors) ? $errors : array();
$val = function ($key) use ($f) {
    return isset($f[$key]) && $f[$key] !== null ? (string)$f[$key] : '';
};
$err = function ($key) use ($errors) {
    return isset($errors[$key]) ? '<span class="form-help text-danger">' . e($errors[$key]) . '</span>' : '';
};
?>
<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-error"><?php echo e($errors['general']); ?></div>
<?php elseif (!empty($errors)): ?>
    <div class="alert alert-error">Please correct the highlighted fields.</div>
<?php endif; ?>

<?php echo csrf_field(); ?>

<div class="card">
    <div class="card-body">
        <div class="form-group">
            <label for="title">Deal title <span class="required">*</span></label>
            <input type="text" id="title" name="title" class="form-control" maxlength="255" required value="<?php echo e($val('title')); ?>">
            <?php echo $err('title'); ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="client_id">Client</label>
                <select id="client_id" name="client_id" class="form-control">
                    <option value="">&mdash; None &mdash;</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?php echo (int)$client['id']; ?>"<?php echo $val('client_id') === (string)$client['id'] ? ' selected' : ''; ?>><?php echo e(trim($client['first_name'] . ' ' . ($client['last_name'] ?? ''))); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('client_id'); ?>
            </div>
            <div class="form-group">
                <label for="property_id">Property</label>
                <select id="property_id" name="property_id" class="form-control">
                    <option value="">&mdash; None &mdash;</option>
                    <?php foreach ($properties as $property): ?>
                        <option value="<?php echo (int)$property['id']; ?>"<?php echo $val('property_id') === (string)$property['id'] ? ' selected' : ''; ?>><?php echo e($property['title'] . ' (' . $property['city'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('property_id'); ?>
            </div>
            <div class="form-group">
                <label for="assigned_to">Assigned to</label>
                <select id="assigned_to" name="assigned_to" class="form-control">
                    <option value="">&mdash; Unassigned &mdash;</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo (int)$agent['id']; ?>"<?php echo $val('assigned_to') === (string)$agent['id'] ? ' selected' : ''; ?>><?php echo e($agent['first_name'] . ' ' . $agent['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('assigned_to'); ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="stage">Stage</label>
                <select id="stage" name="stage" class="form-control">
                    <?php foreach ($stages as $value => $label): ?>
                        <option value="<?php echo e($value); ?>"<?php echo ($val('stage') === '' ? 'lead' : $val('stage')) === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('stage'); ?>
            </div>
            <div class="form-group">
                <label for="probability">Probability (%)</label>
                <input type="number" id="probability" name="probability" class="form-control" min="0" max="100" step="1" value="<?php echo e($val('probability')); ?>">
                <?php echo $err('probability'); ?>
            </div>
            <div class="form-group">
                <label for="expected_close_date">Expected close date</label>
                <input type="date" id="expected_close_date" name="expected_close_date" class="form-control" value="<?php echo e($val('expected_close_date')); ?>">
                <?php echo $err('expected_close_date'); ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="deal_value">Deal value</label>
                <input type="number" id="deal_value" name="deal_value" class="form-control" min="0" step="0.01" value="<?php echo e($val('deal_value')); ?>">
                <?php echo $err('deal_value'); ?>
            </div>
            <div class="form-group">
                <label for="commission">Commission</label>
                <input type="number" id="commission" name="commission" class="form-control" min="0" step="0.01" value="<?php echo e($val('commission')); ?>">
                <?php echo $err('commission'); ?>
            </div>
        </div>

        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" class="form-control" rows="5"><?php echo e($val('notes')); ?></textarea>
        </div>
    </div>
</div>

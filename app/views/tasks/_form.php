<?php
// FILE: /app/views/tasks/_form.php
// Shared task form. Expects: $formData, $errors, $agents, $relatedName, $formAction, $submitLabel, $cancelUrl
$fd = isset($formData) && is_array($formData) ? $formData : array();
$errors = isset($errors) && is_array($errors) ? $errors : array();
$val = function ($key, $default = '') use ($fd) {
    return isset($fd[$key]) && $fd[$key] !== null ? (string)$fd[$key] : $default;
};
$err = function ($key) use ($errors) {
    return isset($errors[$key]) ? '<span class="error">' . e($errors[$key]) . '</span>' : '';
};
$priorities = array('low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent');
$statuses = array('pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled');
$relatedTypes = array('lead' => 'Lead', 'client' => 'Client', 'property' => 'Property', 'deal' => 'Deal');
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="POST" action="<?php echo e(url($formAction)); ?>" class="card">
    <?php echo csrf_field(); ?>
    <div class="card-body">
        <div class="form-group">
            <label for="title">Title <span class="required">*</span></label>
            <input type="text" id="title" name="title" class="form-control" maxlength="255" required value="<?php echo e($val('title')); ?>">
            <?php echo $err('title'); ?>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="4"><?php echo e($val('description')); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="assigned_to">Assign To</label>
                <select id="assigned_to" name="assigned_to" class="form-control">
                    <option value="">Unassigned</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo (int)$agent['id']; ?>" <?php echo $val('assigned_to') === (string)$agent['id'] ? 'selected' : ''; ?>>
                            <?php echo e($agent['first_name'] . ' ' . $agent['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('assigned_to'); ?>
            </div>
            <div class="form-group">
                <label for="due_date">Due Date</label>
                <input type="datetime-local" id="due_date" name="due_date" class="form-control" value="<?php echo e($val('due_date')); ?>">
                <?php echo $err('due_date'); ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="priority">Priority</label>
                <select id="priority" name="priority" class="form-control">
                    <?php foreach ($priorities as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $val('priority', 'medium') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('priority'); ?>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <?php foreach ($statuses as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $val('status', 'pending') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('status'); ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="related_to_type">Related To</label>
                <select id="related_to_type" name="related_to_type" class="form-control">
                    <option value="">Nothing</option>
                    <?php foreach ($relatedTypes as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $val('related_to_type') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('related_to_type'); ?>
            </div>
            <div class="form-group">
                <label for="related_to_id">Record ID</label>
                <input type="number" id="related_to_id" name="related_to_id" class="form-control" min="1" value="<?php echo e($val('related_to_id')); ?>">
                <?php if (!empty($relatedName)): ?>
                    <div class="form-help">Linked to: <?php echo e($relatedName); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-footer form-actions">
        <button type="submit" class="btn btn-primary"><?php echo e($submitLabel); ?></button>
        <a href="<?php echo e(url($cancelUrl)); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>

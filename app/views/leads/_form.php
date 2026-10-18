<?php
// FILE: /app/views/leads/_form.php
// Shared lead form. Expects: $formData (array), $errors (array), $agents (array),
// $formAction (string path), $submitLabel (string), $showStatus (bool)
$fd = isset($formData) && is_array($formData) ? $formData : array();
$errors = isset($errors) && is_array($errors) ? $errors : array();
$val = function ($key, $default = '') use ($fd) {
    return isset($fd[$key]) && $fd[$key] !== null ? (string)$fd[$key] : $default;
};
$err = function ($key) use ($errors) {
    return isset($errors[$key]) ? '<span class="error">' . e($errors[$key]) . '</span>' : '';
};
$statusLabels = array('new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'proposal' => 'Proposal',
                      'negotiation' => 'Negotiation', 'won' => 'Won', 'lost' => 'Lost');
$interestLabels = array('buy' => 'Buy', 'sell' => 'Sell', 'rent' => 'Rent', 'lease' => 'Lease');
$sourceSuggestions = array('Website', 'Referral', 'Social Media', 'Advertisement', 'Walk-in', 'Phone', 'Email', 'API', 'Other');
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="POST" action="<?php echo e(url($formAction)); ?>" class="card">
    <?php echo csrf_field(); ?>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label for="first_name">First Name <span class="required">*</span></label>
                <input type="text" id="first_name" name="first_name" class="form-control" maxlength="100" required
                       value="<?php echo e($val('first_name')); ?>">
                <?php echo $err('first_name'); ?>
            </div>
            <div class="form-group">
                <label for="last_name">Last Name</label>
                <input type="text" id="last_name" name="last_name" class="form-control" maxlength="100"
                       value="<?php echo e($val('last_name')); ?>">
                <?php echo $err('last_name'); ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" maxlength="255"
                       value="<?php echo e($val('email')); ?>">
                <?php echo $err('email'); ?>
            </div>
            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone" class="form-control" maxlength="50"
                       value="<?php echo e($val('phone')); ?>">
                <?php echo $err('phone'); ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="source">Source</label>
                <input type="text" id="source" name="source" class="form-control" maxlength="100" list="lead-sources"
                       value="<?php echo e($val('source')); ?>">
                <datalist id="lead-sources">
                    <?php foreach ($sourceSuggestions as $s): ?>
                        <option value="<?php echo e($s); ?>">
                    <?php endforeach; ?>
                </datalist>
                <?php echo $err('source'); ?>
            </div>
            <div class="form-group">
                <label for="interest_type">Interest Type</label>
                <select id="interest_type" name="interest_type" class="form-control">
                    <option value="">Select...</option>
                    <?php foreach ($interestLabels as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $val('interest_type') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('interest_type'); ?>
            </div>
            <?php if (!empty($showStatus)): ?>
            <div class="form-group">
                <label for="status">Status <span class="required">*</span></label>
                <select id="status" name="status" class="form-control" required>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $val('status', 'new') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('status'); ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="budget_min">Budget Min</label>
                <input type="number" id="budget_min" name="budget_min" class="form-control" min="0" step="0.01"
                       value="<?php echo e($val('budget_min')); ?>">
                <?php echo $err('budget_min'); ?>
            </div>
            <div class="form-group">
                <label for="budget_max">Budget Max</label>
                <input type="number" id="budget_max" name="budget_max" class="form-control" min="0" step="0.01"
                       value="<?php echo e($val('budget_max')); ?>">
                <?php echo $err('budget_max'); ?>
            </div>
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
        </div>

        <div class="form-group">
            <label for="tags">Tags</label>
            <input type="text" id="tags" name="tags" class="form-control" maxlength="500"
                   placeholder="e.g. first-time buyer, investor, downtown"
                   value="<?php echo e($val('tags')); ?>">
            <div class="form-help">Separate tags with commas.</div>
            <?php echo $err('tags'); ?>
        </div>

        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" class="form-control" rows="4"><?php echo e($val('notes')); ?></textarea>
        </div>
    </div>
    <div class="card-footer form-actions">
        <button type="submit" class="btn btn-primary"><?php echo e($submitLabel); ?></button>
        <a href="<?php echo e(url(isset($cancelUrl) ? $cancelUrl : 'leads/index')); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>

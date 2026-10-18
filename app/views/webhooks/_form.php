<?php
// FILE: /app/views/webhooks/_form.php
// Shared create/edit form. Expects $formData, $errors, $availableEvents, $formAction, $submitLabel.
$selectedEvents = Webhook::parseEvents(isset($formData['events']) ? $formData['events'] : '');
$fieldError = function ($field) use ($errors) {
    return isset($errors[$field]) ? '<div class="form-help text-danger">' . e($errors[$field]) . '</div>' : '';
};
?>
<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-error"><?php echo e($errors['general']); ?></div>
<?php endif; ?>

<form method="POST" action="<?php echo e(url($formAction)); ?>" class="card">
    <?php echo csrf_field(); ?>
    <div class="card-body">
        <div class="form-group">
            <label for="name">Name <span class="required">*</span></label>
            <input type="text" id="name" name="name" class="form-control" maxlength="255" required
                   value="<?php echo e(isset($formData['name']) ? $formData['name'] : ''); ?>" placeholder="e.g. Zapier lead sync">
            <?php echo $fieldError('name'); ?>
        </div>

        <div class="form-group">
            <label for="url">Endpoint URL <span class="required">*</span></label>
            <input type="url" id="url" name="url" class="form-control" maxlength="512" required
                   value="<?php echo e(isset($formData['url']) ? $formData['url'] : ''); ?>" placeholder="https://example.com/webhooks/splashestate">
            <div class="form-help">Events are sent as JSON <code>POST</code> requests. Only http(s) URLs on public hosts are accepted; redirects are not followed.</div>
            <?php echo $fieldError('url'); ?>
        </div>

        <div class="form-group">
            <label>Events <span class="required">*</span></label>
            <div class="grid-3">
                <?php foreach ($availableEvents as $event => $label): ?>
                    <label class="small" style="display:flex;gap:.4rem;align-items:center;font-weight:normal;">
                        <input type="checkbox" name="events[]" value="<?php echo e($event); ?>"
                            <?php echo in_array($event, $selectedEvents, true) ? 'checked' : ''; ?>>
                        <span><?php echo e($label); ?> <code><?php echo e($event); ?></code></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="form-help">Choosing <code>*</code> subscribes the endpoint to every event, including ones added later.</div>
            <?php echo $fieldError('events'); ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="active" <?php echo (isset($formData['status']) ? $formData['status'] : 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo (isset($formData['status']) ? $formData['status'] : '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="form-group">
                <label for="max_retries">Max delivery attempts</label>
                <input type="number" id="max_retries" name="max_retries" class="form-control" min="1" max="10"
                       value="<?php echo (int)(isset($formData['max_retries']) ? $formData['max_retries'] : 3); ?>">
            </div>
            <div class="form-group">
                <label style="display:flex;gap:.4rem;align-items:center;margin-top:1.8rem;">
                    <input type="checkbox" name="retry_enabled" value="1" <?php echo !empty($formData['retry_enabled']) ? 'checked' : ''; ?>>
                    Retry failed deliveries
                </label>
                <div class="form-help">Retries back off exponentially (1, 4, 16 … minutes).</div>
            </div>
        </div>
    </div>
    <div class="card-footer form-actions">
        <button type="submit" class="btn btn-primary"><?php echo e($submitLabel); ?></button>
        <a href="<?php echo e(url($cancelPath)); ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>

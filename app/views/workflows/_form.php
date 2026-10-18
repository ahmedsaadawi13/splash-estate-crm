<?php
// FILE: /app/views/workflows/_form.php
/**
 * Workflow form shared by create.php and edit.php
 * Expects: $mode, $workflow, $actions, $errors, $triggerTypes, $actionTypes, $operators,
 *          $updatableFields, $users, $webhookEvents, $entityType
 */
require_once APP_PATH . '/views/workflows/_helpers.php';

$tc = is_array($workflow['trigger_config']) ? $workflow['trigger_config'] : array();
$tcv = function ($key) use ($tc) {
    return isset($tc[$key]) && is_scalar($tc[$key]) ? (string)$tc[$key] : '';
};
$conditions = is_array($workflow['conditions']) ? array_values($workflow['conditions']) : array();
$formAction = $mode === 'create' ? url('workflows/store') : url('workflows/update/' . (int)$workflow['id']);

$conditionFields = array(
    'status', 'source', 'email', 'phone', 'first_name', 'last_name', 'interest_type', 'budget_min', 'budget_max',
    'tags', 'assigned_to', 'score', 'grade', 'old_status', 'stage', 'old_stage', 'deal_value', 'probability',
    'priority', 'title', 'client_type', 'property_type', 'listing_type', 'price', 'city'
);
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <strong>Please fix the following:</strong>
        <ul class="mt-1">
            <?php foreach ($errors as $error): ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" action="<?php echo e($formAction); ?>" id="wf-form">
    <?php echo csrf_field(); ?>

    <div class="card">
        <div class="card-header"><h2>Basics</h2></div>
        <div class="card-body">
            <div class="form-group">
                <label for="wf-name">Name <span class="required">*</span></label>
                <input type="text" class="form-control" id="wf-name" name="name" value="<?php echo e($workflow['name']); ?>" required maxlength="255">
            </div>
            <div class="form-group">
                <label for="wf-description">Description</label>
                <textarea class="form-control" id="wf-description" name="description" rows="2"><?php echo e($workflow['description'] ?? ''); ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="wf-trigger">Trigger <span class="required">*</span></label>
                    <select class="form-control" id="wf-trigger" name="trigger_type" required>
                        <?php foreach ($triggerTypes as $value => $label): ?>
                            <option value="<?php echo e($value); ?>"<?php echo $workflow['trigger_type'] === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="wf-status">Status</label>
                    <select class="form-control" id="wf-status" name="status">
                        <option value="active"<?php echo $workflow['status'] === 'active' ? ' selected' : ''; ?>>Active</option>
                        <option value="inactive"<?php echo $workflow['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="wf-order">Run order</label>
                    <input type="number" class="form-control" id="wf-order" name="execution_order" value="<?php echo (int)$workflow['execution_order']; ?>" min="0" max="9999">
                    <span class="form-help">Lower numbers run first when several workflows share a trigger.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card" id="wf-schedule"<?php echo $workflow['trigger_type'] === 'scheduled' ? '' : ' style="display:none"'; ?>>
        <div class="card-header"><h2>Schedule</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="wf-frequency">Run</label>
                    <?php echo wf_select('trigger_config[frequency]', $tcv('frequency') !== '' ? $tcv('frequency') : 'daily', array('hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly')); ?>
                </div>
                <div class="form-group">
                    <label for="wf-entity">For each</label>
                    <select class="form-control" id="wf-entity" name="trigger_config[entity]">
                        <option value=""<?php echo $tcv('entity') === '' ? ' selected' : ''; ?>>Nothing (run once per schedule)</option>
                        <option value="lead"<?php echo $tcv('entity') === 'lead' ? ' selected' : ''; ?>>Lead matching the filters</option>
                        <option value="task"<?php echo $tcv('entity') === 'task' ? ' selected' : ''; ?>>Task matching the filters</option>
                        <option value="deal"<?php echo $tcv('entity') === 'deal' ? ' selected' : ''; ?>>Deal matching the filters</option>
                    </select>
                </div>
                <div class="form-group wf-sched-filter" data-entities="lead task deal">
                    <label for="wf-sched-status">Status / stage</label>
                    <select class="form-control" id="wf-sched-status" name="trigger_config[status]">
                        <option value="">Any</option>
                        <?php foreach (array('lead' => 'status', 'task' => 'status', 'deal' => 'stage') as $entity => $column): ?>
                            <optgroup label="<?php echo e(ucfirst($entity)); ?>" data-entity="<?php echo e($entity); ?>">
                                <?php foreach ($updatableFields[$entity][$column]['values'] as $value): ?>
                                    <option value="<?php echo e($value); ?>"<?php echo ($tcv('entity') === $entity && $tcv('status') === $value) ? ' selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $value))); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group wf-sched-filter" data-entities="lead deal task">
                    <label>Created at least (days ago)</label>
                    <input type="number" class="form-control" name="trigger_config[older_than_days]" value="<?php echo e($tcv('older_than_days')); ?>" min="1" max="3650">
                </div>
                <div class="form-group wf-sched-filter" data-entities="lead deal task">
                    <label>Not updated for (days)</label>
                    <input type="number" class="form-control" name="trigger_config[inactive_days]" value="<?php echo e($tcv('inactive_days')); ?>" min="1" max="3650">
                </div>
                <div class="form-group wf-sched-filter" data-entities="task">
                    <label>Due within (hours)</label>
                    <input type="number" class="form-control" name="trigger_config[due_within_hours]" value="<?php echo e($tcv('due_within_hours')); ?>" min="1" max="8760">
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <label class="wf-sched-filter" data-entities="task deal"><input type="checkbox" name="trigger_config[overdue]" value="1"<?php echo !empty($tc['overdue']) ? ' checked' : ''; ?>> Overdue only</label>
                <label class="wf-sched-filter" data-entities="lead"><input type="checkbox" name="trigger_config[unassigned]" value="1"<?php echo !empty($tc['unassigned']) ? ' checked' : ''; ?>> Unassigned leads only</label>
                <label class="wf-sched-filter" data-entities="lead task deal"><input type="checkbox" name="trigger_config[repeat]" value="1"<?php echo !empty($tc['repeat']) ? ' checked' : ''; ?>> Repeat for records already processed</label>
            </div>
            <p class="form-help mt-1">At most <?php echo (int)WorkflowEngine::SCHEDULED_RECORD_CAP; ?> records are processed per run. Without "repeat", each record is processed once by this workflow. Requires the cron job (cron.php).</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Conditions</h2></div>
        <div class="card-body">
            <p class="text-muted small mb-2">All conditions must match. Leave empty to run for every trigger.</p>
            <datalist id="wf-condition-fields">
                <?php foreach ($conditionFields as $field): ?>
                    <option value="<?php echo e($field); ?>">
                <?php endforeach; ?>
            </datalist>
            <div id="wf-conditions">
                <?php foreach ($conditions as $i => $condition): ?>
                    <div class="form-row wf-condition">
                        <input type="text" class="form-control" name="conditions[<?php echo (int)$i; ?>][field]" value="<?php echo e($condition['field'] ?? ''); ?>" list="wf-condition-fields" placeholder="field, e.g. source">
                        <?php echo wf_select('conditions[' . (int)$i . '][operator]', $condition['operator'] ?? 'equals', $operators); ?>
                        <input type="text" class="form-control" name="conditions[<?php echo (int)$i; ?>][value]" value="<?php echo e(isset($condition['value']) && is_scalar($condition['value']) ? $condition['value'] : ''); ?>" placeholder="value">
                        <button type="button" class="btn btn-sm btn-outline wf-remove-condition">Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <template id="wf-condition-template">
                <div class="form-row wf-condition">
                    <input type="text" class="form-control" name="conditions[__INDEX__][field]" list="wf-condition-fields" placeholder="field, e.g. source">
                    <?php echo wf_select('conditions[__INDEX__][operator]', 'equals', $operators); ?>
                    <input type="text" class="form-control" name="conditions[__INDEX__][value]" placeholder="value">
                    <button type="button" class="btn btn-sm btn-outline wf-remove-condition">Remove</button>
                </div>
            </template>
            <button type="button" class="btn btn-sm btn-secondary mt-1" id="wf-add-condition">Add condition</button>
        </div>
    </div>

    <?php require APP_PATH . '/views/workflows/_action_builder.php'; ?>

    <div class="card">
        <div class="card-body">
            <p class="small text-muted mb-1"><strong>Placeholders</strong> (text fields): {{id}}, {{first_name}}, {{last_name}}, {{full_name}}, {{email}}, {{phone}}, {{status}}, {{source}}, {{title}}, {{stage}}, {{deal_value}}, {{score}}, {{grade}}, {{old_status}}, {{assigned_to}}, {{assigned_user_name}}, {{assigned_user_email}}, {{entity_link}}, {{workflow_name}}, {{today}}, {{now}}. Unknown placeholders are left empty.</p>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?php echo $mode === 'create' ? 'Create workflow' : 'Save changes'; ?></button>
                <a href="<?php echo e(url('workflows/index')); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('wf-form');
    var trigger = document.getElementById('wf-trigger');
    var entitySelect = document.getElementById('wf-entity');
    var counter = 1000;

    function currentEntity() {
        var t = trigger.value;
        if (t === 'scheduled') { return entitySelect.value; }
        var prefix = t.split('_')[0];
        return ['lead', 'client', 'property', 'deal', 'task'].indexOf(prefix) >= 0 ? prefix : '';
    }

    function refreshTriggerUi() {
        var scheduled = trigger.value === 'scheduled';
        var entity = currentEntity();
        document.getElementById('wf-schedule').style.display = scheduled ? '' : 'none';

        Array.prototype.forEach.call(document.querySelectorAll('.wf-sched-filter'), function (el) {
            var list = (el.getAttribute('data-entities') || '').split(' ');
            el.style.display = (entity !== '' && list.indexOf(entity) >= 0) ? '' : 'none';
        });
        Array.prototype.forEach.call(document.querySelectorAll('#wf-sched-status optgroup'), function (group) {
            var show = group.getAttribute('data-entity') === entity;
            group.style.display = show ? '' : 'none';
            group.disabled = !show;
        });
        Array.prototype.forEach.call(document.querySelectorAll('.wf-field-select'), function (select) {
            Array.prototype.forEach.call(select.querySelectorAll('optgroup'), function (group) {
                var show = group.getAttribute('data-entity') === entity;
                group.style.display = show ? '' : 'none';
                group.disabled = !show;
            });
            if (select.selectedOptions.length && select.selectedOptions[0].parentNode.disabled) {
                select.value = '';
            }
            showFieldValues(select);
        });
    }

    function showFieldValues(select) {
        var help = select.closest('.wf-action').querySelector('.wf-field-values');
        var opt = select.selectedOptions.length ? select.selectedOptions[0] : null;
        var values = opt ? opt.getAttribute('data-values') : '';
        help.textContent = values ? 'Allowed: ' + values : '';
    }

    function renumberActions() {
        var cards = document.querySelectorAll('#wf-actions .wf-action');
        Array.prototype.forEach.call(cards, function (card, i) {
            card.querySelector('.wf-action-number').textContent = (i + 1) + '.';
        });
        document.getElementById('wf-no-actions').style.display = cards.length ? 'none' : '';
    }

    function fromTemplate(id) {
        var html = document.getElementById(id).innerHTML.replace(/__INDEX__/g, String(counter++));
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        return wrap.firstChild;
    }

    document.getElementById('wf-add-action').addEventListener('click', function () {
        var type = document.getElementById('wf-new-action-type').value;
        document.getElementById('wf-actions').appendChild(fromTemplate('wf-template-' + type));
        renumberActions();
        refreshTriggerUi();
    });

    document.getElementById('wf-add-condition').addEventListener('click', function () {
        document.getElementById('wf-conditions').appendChild(fromTemplate('wf-condition-template'));
    });

    form.addEventListener('click', function (event) {
        var target = event.target;
        var card = target.closest('.wf-action');
        if (target.classList.contains('wf-remove') && card) {
            card.parentNode.removeChild(card);
            renumberActions();
        } else if (target.classList.contains('wf-move-up') && card && card.previousElementSibling) {
            card.parentNode.insertBefore(card, card.previousElementSibling);
            renumberActions();
        } else if (target.classList.contains('wf-move-down') && card && card.nextElementSibling) {
            card.parentNode.insertBefore(card.nextElementSibling, card);
            renumberActions();
        } else if (target.classList.contains('wf-remove-condition')) {
            var row = target.closest('.wf-condition');
            row.parentNode.removeChild(row);
        }
    });

    form.addEventListener('change', function (event) {
        if (event.target.classList.contains('wf-field-select')) {
            showFieldValues(event.target);
        }
    });
    trigger.addEventListener('change', refreshTriggerUi);
    entitySelect.addEventListener('change', refreshTriggerUi);

    renumberActions();
    refreshTriggerUi();
})();
</script>

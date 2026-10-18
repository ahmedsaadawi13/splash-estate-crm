<?php
// FILE: /app/views/workflows/_action_builder.php
/**
 * Action builder shared by the create and edit forms.
 * Expects: $actions (list of [action_type, action_config, delay_minutes]), $actionTypes,
 *          $users (id => name), $updatableFields, $webhookEvents.
 * Posts: actions[i][type], actions[i][delay], actions[i][config][key]
 */

require_once APP_PATH . '/views/workflows/_helpers.php';

$wfCtx = array(
    'actionTypes' => $actionTypes,
    'users' => $users,
    'updatableFields' => $updatableFields,
    'webhookEvents' => $webhookEvents,
    'entityType' => $entityType
);
?>
<div class="card">
    <div class="card-header">
        <h2>Actions <span class="required">*</span></h2>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-2">Run in order. A failing action is logged and the next ones still run.</p>

        <div id="wf-actions">
            <?php foreach (array_values($actions) as $i => $action): ?>
                <?php echo wf_render_action($i, $action['action_type'], $action['action_config'], $action['delay_minutes'], $wfCtx); ?>
            <?php endforeach; ?>
        </div>
        <p class="text-muted small" id="wf-no-actions"<?php echo empty($actions) ? '' : ' style="display:none"'; ?>>No actions yet.</p>

        <div class="d-flex gap-1 align-center mt-2">
            <select class="form-control" id="wf-new-action-type" style="max-width:280px;">
                <?php foreach ($actionTypes as $type => $def): ?>
                    <option value="<?php echo e($type); ?>"><?php echo e($def['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-secondary" id="wf-add-action">Add action</button>
        </div>

        <?php foreach ($actionTypes as $type => $def): ?>
            <template id="wf-template-<?php echo e($type); ?>"><?php echo wf_render_action('__INDEX__', $type, array(), 0, $wfCtx); ?></template>
        <?php endforeach; ?>
    </div>
</div>

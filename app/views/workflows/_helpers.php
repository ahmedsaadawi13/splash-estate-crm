<?php
// FILE: /app/views/workflows/_helpers.php
/**
 * Rendering helpers for the workflow form (wf_select, wf_user_select, wf_render_action)
 */

if (!function_exists('wf_user_select')) {
    /**
     * <select> of tenant users with an optional "record's assignee" choice
     */
    function wf_user_select($name, $selected, $users, $emptyLabel) {
        $selected = (string)$selected;
        $html = '<select class="form-control" name="' . e($name) . '">';
        $html .= '<option value="">' . e($emptyLabel) . '</option>';
        $html .= '<option value="{{assigned_to}}"' . ($selected === '{{assigned_to}}' ? ' selected' : '') . '>Record\'s assigned user</option>';
        foreach ($users as $id => $label) {
            $html .= '<option value="' . (int)$id . '"' . ($selected === (string)$id ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        return $html . '</select>';
    }

    /**
     * Simple <select> from value => label
     */
    function wf_select($name, $selected, $options) {
        $html = '<select class="form-control" name="' . e($name) . '">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . e($value) . '"' . ((string)$selected === (string)$value ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        return $html . '</select>';
    }

    /**
     * One action card. $index is a number, or __INDEX__ inside <template>s.
     */
    function wf_render_action($index, $type, $config, $delay, $ctx) {
        $config = is_array($config) ? $config : array();
        $v = function ($key) use ($config) {
            return isset($config[$key]) && is_scalar($config[$key]) ? (string)$config[$key] : '';
        };
        $prefix = 'actions[' . $index . ']';
        $cfg = $prefix . '[config]';
        $label = isset($ctx['actionTypes'][$type]) ? $ctx['actionTypes'][$type]['name'] : $type;
        ob_start();
        ?>
        <div class="card wf-action" data-type="<?php echo e($type); ?>" style="border:1px solid #ecf0f1;">
            <div class="card-header">
                <h3><span class="wf-action-number"></span> <?php echo e($label); ?></h3>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline wf-move-up" title="Move up">&uarr;</button>
                    <button type="button" class="btn btn-sm btn-outline wf-move-down" title="Move down">&darr;</button>
                    <button type="button" class="btn btn-sm btn-danger wf-remove" title="Remove action">Remove</button>
                </div>
            </div>
            <div class="card-body">
                <input type="hidden" name="<?php echo e($prefix); ?>[type]" value="<?php echo e($type); ?>">

                <?php if ($type === 'send_notification'): ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Recipient <span class="required">*</span></label>
                            <?php echo wf_user_select($cfg . '[user_id]', $v('user_id'), $ctx['users'], 'Choose a user...'); ?>
                        </div>
                        <div class="form-group">
                            <label>Link (optional)</label>
                            <input type="text" class="form-control" name="<?php echo e($cfg); ?>[link]" value="<?php echo e($v('link')); ?>" placeholder="defaults to the record, e.g. leads/view/5">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Title <span class="required">*</span></label>
                        <input type="text" class="form-control" name="<?php echo e($cfg); ?>[title]" value="<?php echo e($v('title')); ?>" placeholder="New lead: {{full_name}}" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea class="form-control" name="<?php echo e($cfg); ?>[message]" rows="2"><?php echo e($v('message')); ?></textarea>
                    </div>

                <?php elseif ($type === 'send_email'): ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>To <span class="required">*</span></label>
                            <input type="text" class="form-control" name="<?php echo e($cfg); ?>[to]" value="<?php echo e($v('to')); ?>" placeholder="{{email}} or {{assigned_user_email}}">
                        </div>
                        <div class="form-group">
                            <label>Subject <span class="required">*</span></label>
                            <input type="text" class="form-control" name="<?php echo e($cfg); ?>[subject]" value="<?php echo e($v('subject')); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Body</label>
                        <textarea class="form-control" name="<?php echo e($cfg); ?>[body]" rows="4"><?php echo e($v('body')); ?></textarea>
                        <span class="form-help">Plain text (line breaks kept) or HTML. Placeholder values are HTML-escaped.</span>
                    </div>

                <?php elseif ($type === 'create_task'): ?>
                    <div class="form-group">
                        <label>Title <span class="required">*</span></label>
                        <input type="text" class="form-control" name="<?php echo e($cfg); ?>[title]" value="<?php echo e($v('title')); ?>" placeholder="Follow up with {{full_name}}" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="<?php echo e($cfg); ?>[description]" rows="2"><?php echo e($v('description')); ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Assign to</label>
                            <?php echo wf_user_select($cfg . '[assigned_to]', $v('assigned_to'), $ctx['users'], 'Unassigned'); ?>
                        </div>
                        <div class="form-group">
                            <label>Due in</label>
                            <input type="text" class="form-control" name="<?php echo e($cfg); ?>[due_in]" value="<?php echo e($v('due_in')); ?>" placeholder="+1 day, 4 hours, 1 week">
                        </div>
                        <div class="form-group">
                            <label>Priority</label>
                            <?php echo wf_select($cfg . '[priority]', $v('priority') !== '' ? $v('priority') : 'medium', array('low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent')); ?>
                        </div>
                    </div>

                <?php elseif ($type === 'update_field'): ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Field <span class="required">*</span></label>
                            <select class="form-control wf-field-select" name="<?php echo e($cfg); ?>[field]">
                                <option value="">Choose a field...</option>
                                <?php foreach ($ctx['updatableFields'] as $entity => $fields): ?>
                                    <optgroup label="<?php echo e(ucfirst($entity)); ?>" data-entity="<?php echo e($entity); ?>">
                                        <?php foreach ($fields as $field => $def): ?>
                                            <option value="<?php echo e($field); ?>"<?php echo ($v('field') === $field && $entity === $ctx['entityType']) ? ' selected' : ''; ?>
                                                data-values="<?php echo e(isset($def['values']) ? implode(', ', $def['values']) : ''); ?>"><?php echo e($def['label']); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <span class="form-help">Fields of the record that triggered the workflow.</span>
                        </div>
                        <div class="form-group">
                            <label>New value <span class="required">*</span></label>
                            <input type="text" class="form-control" name="<?php echo e($cfg); ?>[value]" value="<?php echo e($v('value')); ?>">
                            <span class="form-help wf-field-values"></span>
                        </div>
                    </div>

                <?php elseif ($type === 'assign_to_user'): ?>
                    <div class="form-group">
                        <label>Assign the record to <span class="required">*</span></label>
                        <?php echo wf_user_select($cfg . '[user_id]', $v('user_id'), $ctx['users'], 'Choose a user...'); ?>
                    </div>

                <?php elseif ($type === 'add_tag'): ?>
                    <div class="form-group">
                        <label>Tag <span class="required">*</span></label>
                        <input type="text" class="form-control" name="<?php echo e($cfg); ?>[tag]" value="<?php echo e($v('tag')); ?>" maxlength="50" placeholder="e.g. hot-lead">
                        <span class="form-help">Added to the lead's tags if not already present.</span>
                    </div>

                <?php elseif ($type === 'create_deal'): ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Deal title <span class="required">*</span></label>
                            <input type="text" class="form-control" name="<?php echo e($cfg); ?>[title]" value="<?php echo e($v('title')); ?>" placeholder="Deal - {{full_name}}">
                        </div>
                        <div class="form-group">
                            <label>Value</label>
                            <input type="text" class="form-control" name="<?php echo e($cfg); ?>[deal_value]" value="<?php echo e($v('deal_value')); ?>" placeholder="{{budget_max}} or 250000">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Stage</label>
                            <?php echo wf_select($cfg . '[stage]', $v('stage') !== '' ? $v('stage') : 'lead', array('lead' => 'Lead', 'qualified' => 'Qualified', 'viewing' => 'Viewing', 'offer' => 'Offer', 'negotiation' => 'Negotiation', 'contract' => 'Contract')); ?>
                        </div>
                        <div class="form-group">
                            <label>Assign to</label>
                            <?php echo wf_user_select($cfg . '[assigned_to]', $v('assigned_to'), $ctx['users'], 'Unassigned'); ?>
                        </div>
                    </div>

                <?php elseif ($type === 'send_webhook'): ?>
                    <div class="form-group">
                        <label>Event</label>
                        <?php echo wf_select($cfg . '[event]', $v('event') !== '' ? $v('event') : 'workflow.triggered', $ctx['webhookEvents']); ?>
                        <span class="form-help">Delivered to your webhooks subscribed to this event (or to all events).</span>
                    </div>
                <?php endif; ?>

                <div class="form-group" style="max-width:260px;">
                    <label>Delay (minutes after trigger)</label>
                    <input type="number" class="form-control" name="<?php echo e($prefix); ?>[delay]" value="<?php echo (int)$delay; ?>" min="0" max="43200">
                    <span class="form-help">0 = run immediately. Delayed actions are run by the scheduler (cron).</span>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('wf_status_badge')) {
    /**
     * Badge for workflow / execution / action-log statuses
     */
    function wf_status_badge($status) {
        $map = array(
            'active' => 'success', 'inactive' => 'secondary', 'completed' => 'success',
            'failed' => 'danger', 'running' => 'info', 'pending' => 'info',
            'scheduled' => 'primary', 'skipped' => 'secondary', 'cancelled' => 'secondary'
        );
        $status = (string)$status;
        $class = isset($map[$status]) ? $map[$status] : 'secondary';
        return '<span class="badge badge-' . $class . '">' . e(ucfirst($status)) . '</span>';
    }

    /**
     * Human readable trigger, including the schedule for scheduled workflows
     */
    function wf_trigger_summary($workflow, $triggerTypes) {
        $type = (string)$workflow['trigger_type'];
        $label = isset($triggerTypes[$type]) ? $triggerTypes[$type] : $type;
        if ($type !== 'scheduled') {
            return $label;
        }
        $config = WorkflowEngine::decodeJson($workflow['trigger_config']);
        $parts = array(ucfirst(isset($config['frequency']) ? $config['frequency'] : 'daily'));
        if (!empty($config['entity'])) {
            $parts[] = 'for each ' . $config['entity'];
            if (!empty($config['status'])) {
                $parts[] = '(' . str_replace('_', ' ', $config['status']) . ')';
            }
            if (!empty($config['overdue'])) {
                $parts[] = 'overdue';
            }
            if (!empty($config['older_than_days'])) {
                $parts[] = 'older than ' . (int)$config['older_than_days'] . 'd';
            }
            if (!empty($config['inactive_days'])) {
                $parts[] = 'idle ' . (int)$config['inactive_days'] . 'd';
            }
            if (!empty($config['due_within_hours'])) {
                $parts[] = 'due within ' . (int)$config['due_within_hours'] . 'h';
            }
        }
        return 'Scheduled: ' . implode(' ', $parts);
    }

    /**
     * Simple pager that keeps the current query-string filters
     */
    function wf_pager($page, $totalPages, $path, $query = array()) {
        if ($totalPages <= 1) {
            return '';
        }
        $query = array_filter($query, function ($v) { return $v !== '' && $v !== null; });
        $html = '<div class="pagination">';
        for ($i = 1; $i <= $totalPages; $i++) {
            $href = url($path) . '?' . http_build_query(array_merge($query, array('page' => $i)));
            $html .= '<a href="' . e($href) . '" class="page-link' . ($i === (int)$page ? ' active' : '') . '">' . $i . '</a>';
        }
        return $html . '</div>';
    }
}

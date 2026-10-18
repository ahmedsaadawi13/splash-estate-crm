<?php
// FILE: /app/views/partials/bulk_operations.php
/**
 * Bulk actions toolbar. Include in a list view whose rows have
 *   <input type="checkbox" class="bulk-checkbox" value="{id}">
 * and (optionally) a header checkbox with class "bulk-checkbox-header".
 *
 * Variables (set by the including view):
 *   $entityType    string leads|clients|properties|deals|tasks
 *   $agents        array  user id => name, for "Assign to" (optional)
 *   $statusOptions array  status value => label (optional)
 *
 * Actions call BulkOperationsController (JSON, CSRF header added by the
 * layout's fetch() wrapper). Buttons are only shown when the user has the
 * matching permission; the server re-checks every permission.
 */
$boEntity = isset($entityType) && is_string($entityType) ? $entityType : 'leads';
$boAgents = isset($agents) && is_array($agents) ? $agents : array();
$boStatuses = isset($statusOptions) && is_array($statusOptions) ? $statusOptions : array();
$boPerm = $boEntity; // permission prefix equals the entity type (leads.edit, deals.delete, ...)
$boCanEdit = can($boPerm . '.edit');
$boCanDelete = can($boPerm . '.delete');
$boCanExport = $boEntity === 'leads' ? can('leads.export') : (can($boPerm . '.view') && can('reports.export'));
$boCanEmail = $boCanEdit && in_array($boEntity, array('leads', 'clients'), true);
$boCanConvert = $boEntity === 'leads' && $boCanEdit && can('clients.create');
$boCanAssign = $boCanEdit && !empty($boAgents) && $boEntity !== 'clients';
$boHasActions = $boCanEdit || $boCanDelete || $boCanExport;
?>

<?php if ($boHasActions): ?>
<div id="bulkActionsToolbar" class="bulk-toolbar" style="display: none;" data-entity="<?php echo e($boEntity); ?>" role="region" aria-label="Bulk actions">
    <span class="bulk-count"><strong id="selectedCount">0</strong> selected</span>

    <?php if ($boCanAssign): ?>
        <label for="bulkAssignAgent" class="sr-only">Assign to</label>
        <select id="bulkAssignAgent" class="form-control">
            <option value="">Assign to&hellip;</option>
            <?php foreach ($boAgents as $boAgentId => $boAgentName): ?>
                <option value="<?php echo (int)$boAgentId; ?>"><?php echo e($boAgentName); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-sm btn-primary" data-bulk-action="assign">Assign</button>
    <?php endif; ?>

    <?php if ($boCanEdit && !empty($boStatuses)): ?>
        <label for="bulkUpdateStatus" class="sr-only">Change status</label>
        <select id="bulkUpdateStatus" class="form-control">
            <option value="">Change status to&hellip;</option>
            <?php foreach ($boStatuses as $boValue => $boLabel): ?>
                <option value="<?php echo e($boValue); ?>"><?php echo e($boLabel); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-sm btn-primary" data-bulk-action="status">Update</button>
    <?php endif; ?>

    <?php if ($boCanEmail): ?>
        <button type="button" class="btn btn-sm btn-outline" data-bulk-action="email">Email</button>
    <?php endif; ?>

    <?php if ($boCanConvert): ?>
        <button type="button" class="btn btn-sm btn-success" data-bulk-action="convert">Convert to Clients</button>
    <?php endif; ?>

    <?php if ($boCanExport): ?>
        <form method="POST" action="<?php echo e(url('bulkoperations/export')); ?>" id="bulkExportForm" class="inline-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="entity_type" value="<?php echo e($boEntity); ?>">
            <button type="submit" class="btn btn-sm btn-secondary">Export CSV</button>
        </form>
    <?php endif; ?>

    <?php if ($boCanDelete): ?>
        <button type="button" class="btn btn-sm btn-danger" data-bulk-action="delete">Delete</button>
    <?php endif; ?>

    <button type="button" class="btn btn-sm btn-link bulk-clear" data-bulk-action="clear">Clear selection</button>
    <span id="bulkStatus" class="small" role="status" aria-live="polite"></span>
</div>

<?php if ($boCanEmail): ?>
<div id="bulkEmailModal" class="bo-modal" hidden role="dialog" aria-modal="true" aria-labelledby="bulkEmailTitle">
    <div class="bo-modal-content card">
        <div class="card-header">
            <h3 id="bulkEmailTitle">Email <span id="bulkEmailCount">0</span> selected record(s)</h3>
            <button type="button" class="btn btn-link" data-bulk-close aria-label="Close">&times;</button>
        </div>
        <form id="bulkEmailForm" class="card-body">
            <div class="form-group">
                <label for="bulkEmailSubject">Subject <span class="required">*</span></label>
                <input type="text" id="bulkEmailSubject" class="form-control" required maxlength="200">
            </div>
            <div class="form-group">
                <label for="bulkEmailMessage">Message <span class="required">*</span></label>
                <textarea id="bulkEmailMessage" class="form-control" rows="8" required maxlength="20000"></textarea>
                <p class="form-help">Plain text. Use {first_name} and {last_name} to personalise. Records without a valid email are skipped.
                    At most 50 recipients per send and 200 per hour.</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="bulkEmailSend">Send</button>
                <button type="button" class="btn btn-secondary" data-bulk-close>Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.bulk-toolbar { position: sticky; top: 0; z-index: 50; display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem;
    background: #2c3e50; color: #fff; padding: 0.6rem 0.9rem; border-radius: 6px; margin-bottom: 1rem; }
.bulk-toolbar .form-control { width: auto; min-width: 150px; padding: 0.3rem 0.5rem; }
.bulk-toolbar .inline-form { display: inline; margin: 0; }
.bulk-toolbar .bulk-clear { color: #ecf0f1; }
.bulk-toolbar #bulkStatus { flex-basis: 100%; }
.bulk-toolbar #bulkStatus:empty { display: none; }
.bulk-checkbox, .bulk-checkbox-header { width: 18px; height: 18px; cursor: pointer; }
.bo-modal { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; }
.bo-modal[hidden] { display: none; }
.bo-modal-content { width: 92%; max-width: 600px; max-height: 90vh; overflow-y: auto; margin: 0; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }
</style>

<script>
(function () {
    var ENTITY = <?php echo json_encode($boEntity, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var API = BASE_URL + '/bulkoperations/';
    var busy = false;

    var el = function (id) { return document.getElementById(id); };

    function selectedIds() {
        return Array.prototype.map.call(document.querySelectorAll('.bulk-checkbox:checked'), function (cb) { return cb.value; });
    }

    function refresh() {
        var ids = selectedIds();
        var toolbar = el('bulkActionsToolbar');
        if (!toolbar) { return; }
        toolbar.style.display = ids.length > 0 ? 'flex' : 'none';
        el('selectedCount').textContent = ids.length;
        var header = document.querySelector('.bulk-checkbox-header');
        var all = document.querySelectorAll('.bulk-checkbox');
        if (header) {
            header.checked = all.length > 0 && ids.length === all.length;
            header.indeterminate = ids.length > 0 && ids.length < all.length;
        }
    }

    function setStatus(text, isError) {
        var s = el('bulkStatus');
        s.textContent = text || '';
        s.style.color = isError ? '#ffb3ab' : '#d5f5e3';
    }

    function summary(data, verb) {
        var parts = [];
        var count = data[verb] !== undefined ? data[verb] : 0;
        parts.push(count + ' ' + verb);
        if (data.unchanged) { parts.push(data.unchanged + ' unchanged'); }
        if (data.skipped) { parts.push(data.skipped + ' skipped'); }
        if (data.not_found) { parts.push(data.not_found + ' not found'); }
        if (data.failed) { parts.push(data.failed + ' failed'); }
        return parts.join(', ');
    }

    function call(action, payload, verb, reload) {
        if (busy) { return Promise.resolve(); }
        busy = true;
        setStatus('Working…');
        payload.entity_type = ENTITY;
        payload.ids = selectedIds();
        return fetch(API + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () { return { success: false, error: 'Unexpected server response (' + response.status + ')' }; });
        }).then(function (data) {
            busy = false;
            if (!data.success) { throw new Error(data.error || data.message || 'Request failed'); }
            setStatus('Done: ' + summary(data, verb) + '.');
            if (reload) { window.setTimeout(function () { window.location.reload(); }, 900); }
            return data;
        }).catch(function (err) {
            busy = false;
            setStatus('Error: ' + err.message, true);
        });
    }

    var actions = {
        assign: function () {
            var userId = el('bulkAssignAgent').value;
            if (!userId) { setStatus('Choose a user to assign to.', true); return; }
            call('assign', { user_id: userId }, 'updated', true);
        },
        status: function () {
            var status = el('bulkUpdateStatus').value;
            if (!status) { setStatus('Choose a status.', true); return; }
            call('updateStatus', { status: status }, 'updated', true);
        },
        delete: function () {
            var n = selectedIds().length;
            if (!window.confirm('Permanently delete ' + n + ' record(s)? This cannot be undone.')) { return; }
            call('delete', {}, 'deleted', true);
        },
        convert: function () {
            var n = selectedIds().length;
            if (!window.confirm('Convert ' + n + ' lead(s) to clients? Converted leads are marked as won.')) { return; }
            call('convertLeads', {}, 'converted', true);
        },
        email: function () {
            el('bulkEmailCount').textContent = selectedIds().length;
            el('bulkEmailModal').hidden = false;
            el('bulkEmailSubject').focus();
        },
        clear: function () {
            Array.prototype.forEach.call(document.querySelectorAll('.bulk-checkbox'), function (cb) { cb.checked = false; });
            refresh();
        }
    };

    function closeEmail() {
        var modal = el('bulkEmailModal');
        if (modal) { modal.hidden = true; }
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target.classList.contains('bulk-checkbox-header')) {
            Array.prototype.forEach.call(document.querySelectorAll('.bulk-checkbox'), function (cb) { cb.checked = target.checked; });
            refresh();
        } else if (target.classList.contains('bulk-checkbox')) {
            refresh();
        }
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-bulk-action]') : null;
        if (button && actions[button.getAttribute('data-bulk-action')]) {
            event.preventDefault();
            if (selectedIds().length === 0 && button.getAttribute('data-bulk-action') !== 'clear') {
                setStatus('Select at least one record.', true);
                return;
            }
            actions[button.getAttribute('data-bulk-action')]();
        }
        if (event.target.closest && event.target.closest('[data-bulk-close]')) {
            closeEmail();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var exportForm = el('bulkExportForm');
        if (exportForm) {
            exportForm.addEventListener('submit', function (event) {
                Array.prototype.forEach.call(exportForm.querySelectorAll('input[name="ids[]"]'), function (input) { input.remove(); });
                var ids = selectedIds();
                if (ids.length === 0) { event.preventDefault(); setStatus('Select at least one record.', true); return; }
                ids.forEach(function (id) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = id;
                    exportForm.appendChild(input);
                });
            });
        }

        var emailForm = el('bulkEmailForm');
        if (emailForm) {
            emailForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var subject = el('bulkEmailSubject').value.trim();
                var message = el('bulkEmailMessage').value.trim();
                if (!subject || !message) { return; }
                el('bulkEmailSend').disabled = true;
                call('email', { subject: subject, message: message }, 'sent', false).then(function (data) {
                    el('bulkEmailSend').disabled = false;
                    if (data) { closeEmail(); emailForm.reset(); }
                });
            });
            el('bulkEmailModal').addEventListener('click', function (event) { if (event.target === this) { closeEmail(); } });
        }
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { closeEmail(); } });

        refresh();
    });
})();
</script>
<?php endif; ?>

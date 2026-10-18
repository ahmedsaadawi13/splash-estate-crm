<?php
// FILE: /app/views/calendar/index.php
// Variables: $users (active tenant users: id, first_name, last_name), $canCreate, $canEdit
require APP_PATH . '/views/layouts/header.php';
$currentUserId = (int)(isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0);
?>

<div class="page-header">
    <h1>Task Calendar</h1>
    <div class="header-actions">
        <a href="<?php echo e(url('tasks/index')); ?>" class="btn btn-secondary">Task List</a>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary" id="calNewTask">New Task</button>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap align-center gap-2 cal-filters">
            <div>
                <label for="filterAssignedTo" class="small"><strong>Assigned to</strong></label>
                <select id="filterAssignedTo" class="form-control">
                    <option value="">Everyone</option>
                    <option value="<?php echo $currentUserId; ?>">Me</option>
                    <?php foreach ($users as $user): ?>
                        <?php if ((int)$user['id'] === $currentUserId) { continue; } ?>
                        <option value="<?php echo (int)$user['id']; ?>"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filterStatus" class="small"><strong>Status</strong></label>
                <select id="filterStatus" class="form-control">
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="in_progress">In progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div>
                <label for="filterPriority" class="small"><strong>Priority</strong></label>
                <select id="filterPriority" class="form-control">
                    <option value="">All priorities</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
            <div class="cal-filter-actions">
                <button type="button" class="btn btn-sm btn-outline" id="calClearFilters">Clear filters</button>
            </div>
            <div class="cal-legend small text-muted">
                <span><i style="background:#e74c3c"></i>Urgent</span>
                <span><i style="background:#f39c12"></i>High</span>
                <span><i style="background:#3498db"></i>Medium</span>
                <span><i style="background:#7f8c8d"></i>Low</span>
                <span><i style="background:#27ae60"></i>Completed</span>
            </div>
        </div>
    </div>
</div>

<div id="calMessage" class="alert alert-error" hidden role="alert"></div>

<div class="card">
    <div class="card-body">
        <div id="calendar"></div>
        <div id="calFallback" hidden>
            <p class="text-muted">The calendar component could not be loaded. Upcoming tasks in this period:</p>
            <ul class="timeline" id="calFallbackList"></ul>
        </div>
    </div>
</div>

<?php if ($canCreate): ?>
<div id="taskModal" class="cal-modal" hidden role="dialog" aria-modal="true" aria-labelledby="taskModalTitle">
    <div class="cal-modal-content card">
        <div class="card-header">
            <h3 id="taskModalTitle">New Task</h3>
            <button type="button" class="btn btn-link" data-cal-close aria-label="Close">&times;</button>
        </div>
        <form id="taskForm" class="card-body">
            <div id="taskFormError" class="alert alert-error" hidden></div>
            <div class="form-group">
                <label for="taskTitle">Title <span class="required">*</span></label>
                <input type="text" id="taskTitle" class="form-control" required maxlength="255">
            </div>
            <div class="form-group">
                <label for="taskDescription">Description</label>
                <textarea id="taskDescription" class="form-control" rows="3"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="taskDueDate">Due <span class="required">*</span></label>
                    <input type="datetime-local" id="taskDueDate" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="taskPriority">Priority</label>
                    <select id="taskPriority" class="form-control">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="taskAssignedTo">Assigned to</label>
                <select id="taskAssignedTo" class="form-control">
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo (int)$user['id']; ?>" <?php echo (int)$user['id'] === $currentUserId ? 'selected' : ''; ?>>
                            <?php echo e($user['first_name'] . ' ' . $user['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="taskSave">Create Task</button>
                <button type="button" class="btn btn-secondary" data-cal-close>Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="taskDetailsModal" class="cal-modal" hidden role="dialog" aria-modal="true" aria-labelledby="taskDetailsTitle">
    <div class="cal-modal-content card">
        <div class="card-header">
            <h3 id="taskDetailsTitle"></h3>
            <button type="button" class="btn btn-link" data-cal-close aria-label="Close">&times;</button>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <dt>Status</dt><dd id="taskDetailsStatus"></dd>
                <dt>Priority</dt><dd id="taskDetailsPriority"></dd>
                <dt>Due</dt><dd id="taskDetailsDueDate"></dd>
                <dt>Assigned to</dt><dd id="taskDetailsAssignedTo"></dd>
                <dt id="taskDetailsRelatedLabel">Related to</dt><dd id="taskDetailsRelated"></dd>
            </dl>
            <p id="taskDetailsDescription" class="mt-2" style="white-space: pre-wrap;"></p>
            <div class="form-actions">
                <a id="taskDetailsViewLink" href="#" class="btn btn-primary">Open Task</a>
                <button type="button" class="btn btn-secondary" data-cal-close>Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.cal-filters .form-control { width: auto; min-width: 160px; }
.cal-filter-actions { align-self: flex-end; }
.cal-legend { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-left: auto; align-self: flex-end; }
.cal-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }
#calendar { min-height: 500px; }
#calendar .task-done { opacity: 0.6; text-decoration: line-through; }
.cal-modal { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; }
.cal-modal[hidden] { display: none; }
.cal-modal-content { width: 92%; max-width: 560px; max-height: 90vh; overflow-y: auto; margin: 0; }
</style>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.js"></script>
<script>
(function () {
    var CAN_CREATE = <?php echo $canCreate ? 'true' : 'false'; ?>;
    var CAN_EDIT = <?php echo $canEdit ? 'true' : 'false'; ?>;
    var calendar = null;
    var el = function (id) { return document.getElementById(id); };
    var pad = function (n) { return String(n).padStart(2, '0'); };

    /** Local wall-clock "YYYY-MM-DD HH:MM:SS" (no UTC conversion) */
    function localDateTime(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' +
            pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
    }
    function localDate(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }
    function datetimeLocalValue(date) {
        return localDate(date) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }
    function humanize(value) {
        return value ? value.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) : '';
    }
    function showMessage(text) {
        var box = el('calMessage');
        box.textContent = text;
        box.hidden = !text;
    }

    function jsonFetch(url, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (data) {
                if (!response.ok) {
                    throw new Error((data && (data.error || data.message)) || ('Request failed (' + response.status + ')'));
                }
                return data;
            });
        });
    }

    function eventsUrl(start, end) {
        var params = new URLSearchParams({ start: localDate(start), end: localDate(end) });
        ['filterAssignedTo', 'filterStatus', 'filterPriority'].forEach(function (id) {
            var value = el(id).value;
            if (value) { params.set({ filterAssignedTo: 'assigned_to', filterStatus: 'status', filterPriority: 'priority' }[id], value); }
        });
        return BASE_URL + '/calendar/getTasks?' + params.toString();
    }

    function refetch() {
        if (calendar) { calendar.refetchEvents(); } else { renderFallback(); }
    }

    function closeModals() {
        Array.prototype.forEach.call(document.querySelectorAll('.cal-modal'), function (m) { m.hidden = true; });
    }

    function openCreate(date) {
        if (!CAN_CREATE) { return; }
        el('taskForm').reset();
        el('taskFormError').hidden = true;
        var due = date ? new Date(date.getTime()) : new Date();
        if (!date || (date.getHours() === 0 && date.getMinutes() === 0)) { due.setHours(9, 0, 0, 0); }
        el('taskDueDate').value = datetimeLocalValue(due);
        el('taskModal').hidden = false;
        el('taskTitle').focus();
    }

    function showDetails(event) {
        var p = event.extendedProps;
        el('taskDetailsTitle').textContent = event.title;
        el('taskDetailsStatus').textContent = humanize(p.status);
        el('taskDetailsPriority').textContent = humanize(p.priority);
        el('taskDetailsDueDate').textContent = event.start ? event.start.toLocaleString() : '';
        el('taskDetailsAssignedTo').textContent = p.assigned_to_name || 'Unassigned';
        var related = p.related_to_type ? humanize(p.related_to_type) + ' #' + p.related_to_id : '';
        el('taskDetailsRelated').textContent = related || '–';
        el('taskDetailsDescription').textContent = p.description || '';
        el('taskDetailsViewLink').href = BASE_URL + '/tasks/view/' + encodeURIComponent(event.id);
        el('taskDetailsModal').hidden = false;
    }

    function reschedule(info) {
        if (!CAN_EDIT) { info.revert(); return; }
        jsonFetch(BASE_URL + '/calendar/updateTaskDate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: info.event.id, new_date: localDateTime(info.event.start) })
        }).then(function () {
            showMessage('');
        }).catch(function (err) {
            info.revert();
            showMessage('Could not reschedule task: ' + err.message);
        });
    }

    /** Plain list when FullCalendar is unavailable (e.g. CDN blocked) */
    function renderFallback() {
        el('calFallback').hidden = false;
        var start = new Date();
        start.setDate(1);
        var end = new Date(start.getFullYear(), start.getMonth() + 2, 1);
        jsonFetch(eventsUrl(start, end)).then(function (events) {
            var list = el('calFallbackList');
            list.innerHTML = '';
            if (!events.length) {
                var li = document.createElement('li');
                li.textContent = 'No tasks scheduled.';
                list.appendChild(li);
            }
            events.forEach(function (ev) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = BASE_URL + '/tasks/view/' + encodeURIComponent(ev.id);
                a.textContent = ev.title;
                var meta = document.createElement('div');
                meta.className = 'timeline-meta';
                meta.textContent = new Date(ev.start).toLocaleString() + ' · ' + humanize(ev.extendedProps.priority) +
                    ' · ' + humanize(ev.extendedProps.status) + ' · ' + (ev.extendedProps.assigned_to_name || 'Unassigned');
                li.appendChild(a);
                li.appendChild(meta);
                list.appendChild(li);
            });
        }).catch(function (err) { showMessage('Could not load tasks: ' + err.message); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        ['filterAssignedTo', 'filterStatus', 'filterPriority'].forEach(function (id) {
            el(id).addEventListener('change', refetch);
        });
        el('calClearFilters').addEventListener('click', function () {
            el('filterAssignedTo').value = '';
            el('filterStatus').value = '';
            el('filterPriority').value = '';
            refetch();
        });
        document.addEventListener('click', function (event) {
            if (event.target.closest && event.target.closest('[data-cal-close]')) { closeModals(); }
            if (event.target.classList && event.target.classList.contains('cal-modal')) { closeModals(); }
        });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { closeModals(); } });

        if (CAN_CREATE) {
            el('calNewTask').addEventListener('click', function () { openCreate(null); });
            el('taskForm').addEventListener('submit', function (event) {
                event.preventDefault();
                var save = el('taskSave');
                save.disabled = true;
                jsonFetch(BASE_URL + '/calendar/createTask', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: el('taskTitle').value.trim(),
                        description: el('taskDescription').value.trim(),
                        due_date: el('taskDueDate').value,
                        assigned_to: el('taskAssignedTo').value,
                        priority: el('taskPriority').value
                    })
                }).then(function () {
                    save.disabled = false;
                    closeModals();
                    refetch();
                }).catch(function (err) {
                    save.disabled = false;
                    el('taskFormError').textContent = err.message;
                    el('taskFormError').hidden = false;
                });
            });
        }

        if (typeof FullCalendar === 'undefined') {
            renderFallback();
            return;
        }

        calendar = new FullCalendar.Calendar(el('calendar'), {
            initialView: 'dayGridMonth',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
            editable: CAN_EDIT,
            eventDurationEditable: false,
            height: 'auto',
            nowIndicator: true,
            dayMaxEvents: 4,
            eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
            events: function (info, success, failure) {
                jsonFetch(eventsUrl(info.start, info.end)).then(function (events) {
                    showMessage('');
                    success(events);
                }).catch(function (err) {
                    showMessage('Could not load tasks: ' + err.message);
                    failure(err);
                });
            },
            eventClick: function (info) {
                info.jsEvent.preventDefault();
                showDetails(info.event);
            },
            eventDrop: reschedule,
            dateClick: CAN_CREATE ? function (info) { openCreate(info.date); } : undefined
        });
        calendar.render();
    });
})();
</script>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

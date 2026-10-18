<?php
// FILE: /app/views/partials/advanced_filters.php
/**
 * Advanced filter panel + saved searches. Include from a list view.
 *
 * Variables (set by the including view):
 *   $module         string  leads|clients|properties|deals|tasks (saved-search module)
 *   $filterFields   array   list of array('name', 'label', 'type' => text|select|date|daterange|numericrange,
 *                           'options' => value => label (select), 'placeholder' => '')
 *                           daterange "x" submits x_from / x_to; numericrange "x" submits x_min / x_max
 *   $currentFilters array   active filter values (query-string keys)
 *   $sortOptions    array   column => label (the controller must whitelist these)
 *
 * The form submits GET to the current page. Saved searches use the JSON API
 * in SavedSearchController (/savedsearch/*); POSTs carry the CSRF header added
 * by the layout's fetch() wrapper.
 */
$afModule = isset($module) && is_string($module) ? $module : 'leads';
$afFields = isset($filterFields) && is_array($filterFields) ? $filterFields : array();
$afCurrent = isset($currentFilters) && is_array($currentFilters) ? $currentFilters : array();
$afSort = isset($sortOptions) && is_array($sortOptions) ? $sortOptions : array();

// Current value of a filter as a string (arrays / missing => '')
$afValue = function ($key) use ($afCurrent) {
    return isset($afCurrent[$key]) && is_scalar($afCurrent[$key]) ? (string)$afCurrent[$key] : '';
};

// Labels for the active-filter chips (key => label, and select option labels)
$afLabels = array('sort' => 'Sort', 'direction' => 'Direction');
$afOptionLabels = array();
$afHasActive = false;
foreach ($afFields as $afField) {
    $afName = (string)$afField['name'];
    $afType = isset($afField['type']) ? $afField['type'] : 'text';
    if ($afType === 'daterange') {
        $afLabels[$afName . '_from'] = $afField['label'] . ' from';
        $afLabels[$afName . '_to'] = $afField['label'] . ' to';
        $afHasActive = $afHasActive || $afValue($afName . '_from') !== '' || $afValue($afName . '_to') !== '';
    } elseif ($afType === 'numericrange') {
        $afLabels[$afName . '_min'] = $afField['label'] . ' min';
        $afLabels[$afName . '_max'] = $afField['label'] . ' max';
        $afHasActive = $afHasActive || $afValue($afName . '_min') !== '' || $afValue($afName . '_max') !== '';
    } else {
        $afLabels[$afName] = $afField['label'];
        $afHasActive = $afHasActive || $afValue($afName) !== '';
        if ($afType === 'select' && !empty($afField['options']) && is_array($afField['options'])) {
            foreach ($afField['options'] as $afOptValue => $afOptLabel) {
                $afOptionLabels[$afName][(string)$afOptValue] = (string)$afOptLabel;
            }
        }
    }
}
foreach ($afSort as $afSortValue => $afSortLabel) {
    $afOptionLabels['sort'][(string)$afSortValue] = (string)$afSortLabel;
}
$afOptionLabels['direction'] = array('DESC' => 'Descending', 'ASC' => 'Ascending');
$afDirection = strtoupper($afValue('direction')) === 'ASC' ? 'ASC' : 'DESC';
?>

<div class="card af-panel" id="advancedFilters" data-module="<?php echo e($afModule); ?>">
    <div class="card-body">
        <div class="d-flex flex-wrap align-center gap-1">
            <button type="button" class="btn btn-sm btn-outline" id="afToggle" aria-expanded="<?php echo $afHasActive ? 'true' : 'false'; ?>" aria-controls="afFilterPanel">
                <span id="afToggleIcon" aria-hidden="true"><?php echo $afHasActive ? '&#9650;' : '&#9660;'; ?></span> Advanced Filters
            </button>
            <button type="button" class="btn btn-sm btn-success" id="afSaveBtn" <?php echo $afHasActive ? '' : 'disabled title="Apply a filter first"'; ?>>Save Search</button>
            <label for="afSavedSelect" class="sr-only">Saved searches</label>
            <select id="afSavedSelect" class="form-control af-saved-select">
                <option value="">Saved searches&hellip;</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline" id="afDefaultBtn" disabled>Set as default</button>
            <button type="button" class="btn btn-sm btn-danger" id="afDeleteBtn" disabled>Delete</button>
            <button type="button" class="btn btn-sm btn-secondary" id="afClearBtn">Clear All</button>
            <span id="afStatus" class="small text-muted" role="status" aria-live="polite"></span>
        </div>

        <div id="afFilterPanel" class="mt-2" <?php echo $afHasActive ? '' : 'hidden'; ?>>
            <form method="GET" action="" id="afFilterForm">
                <div class="af-grid">
                    <?php foreach ($afFields as $afField): ?>
                        <?php
                        $afName = (string)$afField['name'];
                        $afType = isset($afField['type']) ? $afField['type'] : 'text';
                        $afId = 'filter_' . $afName;
                        ?>
                        <div class="form-group">
                            <label for="<?php echo e($afType === 'daterange' ? $afId . '_from' : ($afType === 'numericrange' ? $afId . '_min' : $afId)); ?>"><?php echo e($afField['label']); ?></label>

                            <?php if ($afType === 'select'): ?>
                                <select name="<?php echo e($afName); ?>" id="<?php echo e($afId); ?>" class="form-control">
                                    <option value="">All</option>
                                    <?php foreach ((isset($afField['options']) && is_array($afField['options']) ? $afField['options'] : array()) as $afOptValue => $afOptLabel): ?>
                                        <option value="<?php echo e($afOptValue); ?>" <?php echo $afValue($afName) === (string)$afOptValue ? 'selected' : ''; ?>><?php echo e($afOptLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($afType === 'date'): ?>
                                <input type="date" name="<?php echo e($afName); ?>" id="<?php echo e($afId); ?>" class="form-control" value="<?php echo e($afValue($afName)); ?>">

                            <?php elseif ($afType === 'daterange'): ?>
                                <div class="af-range">
                                    <input type="date" name="<?php echo e($afName); ?>_from" id="<?php echo e($afId); ?>_from" class="form-control" aria-label="<?php echo e($afField['label']); ?> from" value="<?php echo e($afValue($afName . '_from')); ?>">
                                    <span class="text-muted">to</span>
                                    <input type="date" name="<?php echo e($afName); ?>_to" id="<?php echo e($afId); ?>_to" class="form-control" aria-label="<?php echo e($afField['label']); ?> to" value="<?php echo e($afValue($afName . '_to')); ?>">
                                </div>

                            <?php elseif ($afType === 'numericrange'): ?>
                                <div class="af-range">
                                    <input type="number" min="0" step="any" name="<?php echo e($afName); ?>_min" id="<?php echo e($afId); ?>_min" class="form-control" placeholder="Min" aria-label="<?php echo e($afField['label']); ?> minimum" value="<?php echo e($afValue($afName . '_min')); ?>">
                                    <span class="text-muted">&ndash;</span>
                                    <input type="number" min="0" step="any" name="<?php echo e($afName); ?>_max" id="<?php echo e($afId); ?>_max" class="form-control" placeholder="Max" aria-label="<?php echo e($afField['label']); ?> maximum" value="<?php echo e($afValue($afName . '_max')); ?>">
                                </div>

                            <?php else: ?>
                                <input type="text" name="<?php echo e($afName); ?>" id="<?php echo e($afId); ?>" class="form-control" maxlength="255"
                                       placeholder="<?php echo e(isset($afField['placeholder']) ? $afField['placeholder'] : ''); ?>"
                                       value="<?php echo e($afValue($afName)); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($afSort)): ?>
                    <div class="form-group">
                        <label for="filter_sort">Sort By</label>
                        <select name="sort" id="filter_sort" class="form-control">
                            <?php foreach ($afSort as $afSortValue => $afSortLabel): ?>
                                <option value="<?php echo e($afSortValue); ?>" <?php echo $afValue('sort') === (string)$afSortValue ? 'selected' : ''; ?>><?php echo e($afSortLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter_direction">Direction</label>
                        <select name="direction" id="filter_direction" class="form-control">
                            <option value="DESC" <?php echo $afDirection === 'DESC' ? 'selected' : ''; ?>>Descending (newest / Z&ndash;A / highest)</option>
                            <option value="ASC" <?php echo $afDirection === 'ASC' ? 'selected' : ''; ?>>Ascending (oldest / A&ndash;Z / lowest)</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <button type="button" class="btn btn-secondary" id="afResetBtn">Clear</button>
                </div>
            </form>
        </div>

        <div class="af-chips mt-1" id="afChips" aria-label="Active filters"></div>
    </div>
</div>

<div id="afSaveModal" class="af-modal" hidden role="dialog" aria-modal="true" aria-labelledby="afSaveTitle">
    <div class="af-modal-content card">
        <div class="card-header">
            <h3 id="afSaveTitle">Save Current Search</h3>
            <button type="button" class="btn btn-link" id="afSaveClose" aria-label="Close">&times;</button>
        </div>
        <form id="afSaveForm" class="card-body">
            <div class="form-group">
                <label for="afSearchName">Search name <span class="required">*</span></label>
                <input type="text" id="afSearchName" class="form-control" required maxlength="100" placeholder="e.g. Hot leads this month">
            </div>
            <div class="form-group">
                <label><input type="checkbox" id="afSetDefault"> Load this search automatically when I open this list</label>
            </div>
            <p class="form-help">Saves the filters currently applied to the list.</p>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" id="afSaveCancel">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
.af-panel .af-saved-select { width: auto; min-width: 200px; padding: 0.3rem 0.5rem; }
.af-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0 1rem; }
.af-range { display: flex; align-items: center; gap: 0.4rem; }
.af-range .form-control { min-width: 0; }
.af-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.af-chip { display: inline-flex; align-items: center; gap: 0.35rem; background: #eaf2fb; color: #1f4f82; border-radius: 999px; padding: 0.2rem 0.4rem 0.2rem 0.7rem; font-size: 0.85rem; }
.af-chip button { border: 0; background: transparent; color: inherit; cursor: pointer; font-size: 1rem; line-height: 1; padding: 0 0.2rem; }
.af-modal { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; }
.af-modal[hidden] { display: none; }
.af-modal-content { width: 92%; max-width: 480px; margin: 0; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }
</style>

<script>
(function () {
    var MODULE = <?php echo json_encode($afModule, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var LABELS = <?php echo json_encode((object)$afLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var OPTION_LABELS = <?php echo json_encode((object)$afOptionLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var API = BASE_URL + '/savedsearch/';
    var SELECTED_KEY = 'savedSearch.selected.' + MODULE;
    var DEFAULT_KEY = 'savedSearch.defaultApplied.' + MODULE;
    var IGNORED_PARAMS = { page: true, url: true };

    var el = function (id) { return document.getElementById(id); };
    var searches = [];

    function storage(key, value) {
        try {
            if (value === undefined) { return window.sessionStorage.getItem(key); }
            if (value === null) { window.sessionStorage.removeItem(key); } else { window.sessionStorage.setItem(key, value); }
        } catch (e) { /* storage disabled */ }
        return null;
    }

    function setStatus(text, isError) {
        var s = el('afStatus');
        s.textContent = text || '';
        s.className = 'small ' + (isError ? 'text-danger' : 'text-muted');
    }

    function api(path, options) {
        options = options || {};
        options.headers = options.headers || {};
        options.headers['Accept'] = 'application/json';
        options.credentials = 'same-origin';
        return fetch(API + path, options).then(function (response) {
            return response.json().catch(function () { return { success: false, error: 'Unexpected server response' }; })
                .then(function (data) {
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || data.message || ('Request failed (' + response.status + ')'));
                    }
                    return data;
                });
        });
    }

    function post(path, body) {
        return api(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
    }

    /** Active filters from the current URL (what the list is showing) */
    function currentFilters() {
        var params = new URLSearchParams(window.location.search);
        var filters = {};
        params.forEach(function (value, key) {
            if (!IGNORED_PARAMS[key] && value !== '' && LABELS.hasOwnProperty(key)) { filters[key] = value; }
        });
        return filters;
    }

    function applyFilters(filters) {
        var params = new URLSearchParams();
        Object.keys(filters || {}).forEach(function (key) {
            if (filters[key] !== null && filters[key] !== '') { params.set(key, filters[key]); }
        });
        var qs = params.toString();
        window.location.href = window.location.pathname + (qs ? '?' + qs : '');
    }

    function renderChips() {
        var container = el('afChips');
        container.innerHTML = '';
        var filters = currentFilters();
        Object.keys(filters).forEach(function (key) {
            var value = filters[key];
            var shown = OPTION_LABELS[key] && OPTION_LABELS[key][value] !== undefined ? OPTION_LABELS[key][value] : value;
            var chip = document.createElement('span');
            chip.className = 'af-chip';
            chip.appendChild(document.createTextNode((LABELS[key] || key) + ': ' + shown));
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Remove filter ' + (LABELS[key] || key));
            remove.textContent = '×';
            remove.addEventListener('click', function () {
                var next = currentFilters();
                delete next[key];
                storage(SELECTED_KEY, null);
                applyFilters(next);
            });
            chip.appendChild(remove);
            container.appendChild(chip);
        });
    }

    function selectedSearch() {
        var id = el('afSavedSelect').value;
        for (var i = 0; i < searches.length; i++) {
            if (String(searches[i].id) === id) { return searches[i]; }
        }
        return null;
    }

    function updateButtons() {
        var search = selectedSearch();
        el('afDeleteBtn').disabled = !search;
        el('afDefaultBtn').disabled = !search;
        el('afDefaultBtn').textContent = search && search.is_default ? 'Unset default' : 'Set as default';
    }

    function loadList(selectId) {
        return api('index?module=' + encodeURIComponent(MODULE)).then(function (data) {
            searches = data.searches || [];
            var select = el('afSavedSelect');
            select.innerHTML = '';
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = searches.length ? 'Saved searches…' : 'No saved searches';
            select.appendChild(placeholder);
            searches.forEach(function (search) {
                var option = document.createElement('option');
                option.value = search.id;
                option.textContent = search.name + (search.is_default ? ' (default)' : '');
                select.appendChild(option);
            });
            var wanted = selectId !== undefined ? selectId : storage(SELECTED_KEY);
            if (wanted && selectedById(wanted)) { select.value = String(wanted); }
            updateButtons();
            return searches;
        }).catch(function (err) { setStatus('Could not load saved searches: ' + err.message, true); return []; });
    }

    function selectedById(id) {
        for (var i = 0; i < searches.length; i++) {
            if (String(searches[i].id) === String(id)) { return searches[i]; }
        }
        return null;
    }

    function loadSearch(id) {
        setStatus('Loading…');
        api('load/' + encodeURIComponent(id)).then(function (data) {
            storage(SELECTED_KEY, String(data.search.id));
            applyFilters(data.search.filters || {});
        }).catch(function (err) { setStatus(err.message, true); });
    }

    function openModal() {
        if (Object.keys(currentFilters()).length === 0) {
            setStatus('Apply at least one filter before saving a search.', true);
            return;
        }
        el('afSaveForm').reset();
        el('afSaveModal').hidden = false;
        el('afSearchName').focus();
    }

    function closeModal() {
        el('afSaveModal').hidden = true;
        el('afSaveBtn').focus();
    }

    document.addEventListener('DOMContentLoaded', function () {
        el('afToggle').addEventListener('click', function () {
            var panel = el('afFilterPanel');
            panel.hidden = !panel.hidden;
            this.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
            el('afToggleIcon').innerHTML = panel.hidden ? '&#9660;' : '&#9650;';
        });

        var clear = function () {
            storage(SELECTED_KEY, null);
            storage(DEFAULT_KEY, '1'); // do not re-apply the default right after clearing
            window.location.href = window.location.pathname;
        };
        el('afClearBtn').addEventListener('click', clear);
        el('afResetBtn').addEventListener('click', clear);

        el('afFilterForm').addEventListener('submit', function (event) {
            // Drop empty fields so the URL (and saved searches) stay clean
            event.preventDefault();
            var filters = {};
            new FormData(this).forEach(function (value, key) {
                if (typeof value === 'string' && value.trim() !== '') { filters[key] = value.trim(); }
            });
            var sortSelect = el('filter_sort');
            if (sortSelect && filters.sort === sortSelect.options[0].value && filters.direction === 'DESC') {
                // Default sort: keep it out of the URL (and out of saved searches)
                delete filters.sort; delete filters.direction;
            }
            storage(SELECTED_KEY, null);
            applyFilters(filters);
        });

        el('afSavedSelect').addEventListener('change', function () {
            updateButtons();
            if (this.value) { loadSearch(this.value); } else { storage(SELECTED_KEY, null); }
        });

        el('afSaveBtn').addEventListener('click', openModal);
        el('afSaveClose').addEventListener('click', closeModal);
        el('afSaveCancel').addEventListener('click', closeModal);
        el('afSaveModal').addEventListener('click', function (event) { if (event.target === this) { closeModal(); } });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !el('afSaveModal').hidden) { closeModal(); }
        });

        el('afSaveForm').addEventListener('submit', function (event) {
            event.preventDefault();
            var name = el('afSearchName').value.trim();
            if (!name) { return; }
            post('create', {
                name: name,
                module: MODULE,
                filters: currentFilters(),
                is_default: el('afSetDefault').checked ? 1 : 0
            }).then(function (data) {
                el('afSaveModal').hidden = true;
                storage(SELECTED_KEY, String(data.search_id));
                setStatus('Search “' + name + '” saved.');
                return loadList(String(data.search_id));
            }).catch(function (err) { setStatus(err.message, true); el('afSaveModal').hidden = true; });
        });

        el('afDeleteBtn').addEventListener('click', function () {
            var search = selectedSearch();
            if (!search || !window.confirm('Delete the saved search “' + search.name + '”?')) { return; }
            post('delete/' + encodeURIComponent(search.id)).then(function () {
                storage(SELECTED_KEY, null);
                setStatus('Saved search deleted.');
                return loadList('');
            }).catch(function (err) { setStatus(err.message, true); });
        });

        el('afDefaultBtn').addEventListener('click', function () {
            var search = selectedSearch();
            if (!search) { return; }
            post('setDefault/' + encodeURIComponent(search.id), { is_default: search.is_default ? 0 : 1 }).then(function (data) {
                setStatus(data.message);
                return loadList(String(search.id));
            }).catch(function (err) { setStatus(err.message, true); });
        });

        renderChips();
        loadList().then(function (list) {
            // Apply the default search once per browser session when the list is opened without filters
            if (Object.keys(currentFilters()).length > 0 || storage(DEFAULT_KEY)) { return; }
            for (var i = 0; i < list.length; i++) {
                if (list[i].is_default) {
                    storage(DEFAULT_KEY, '1');
                    loadSearch(list[i].id);
                    return;
                }
            }
        });
    });
})();
</script>

<?php
// FILE: /app/views/deals/index.php
require APP_PATH . '/views/layouts/header.php';

$filters = isset($filters) ? $filters : array();
$fv = function ($key) use ($filters) {
    return isset($filters[$key]) ? (string)$filters[$key] : '';
};
$money = function ($amount) {
    return e(View::formatCurrency((float)$amount));
};
$modeUrl = function ($mode) use ($filters) {
    return url('deals/index') . '?' . http_build_query(array_merge($filters, array('view' => $mode)));
};
$pageUrl = function ($page) use ($filters) {
    return url('deals/index') . '?' . http_build_query(array_merge($filters, array('view' => 'table', 'page' => (int)$page)));
};
?>
<div class="page-header">
    <h1>Deal Pipeline</h1>
    <div class="header-actions">
        <a href="<?php echo e($modeUrl('board')); ?>" class="btn <?php echo $mode === 'board' ? 'btn-primary' : 'btn-outline'; ?>">Board</a>
        <a href="<?php echo e($modeUrl('table')); ?>" class="btn <?php echo $mode === 'table' ? 'btn-primary' : 'btn-outline'; ?>">Table</a>
        <?php if (can('deals.create')): ?>
            <a href="<?php echo e(url('deals/create')); ?>" class="btn btn-success">+ New Deal</a>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?php echo (int)$summary['open_count']; ?></div>
        <div class="stat-label">Open deals</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?php echo $money($summary['open_total']); ?></div>
        <div class="stat-label">Open pipeline value</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?php echo $money($summary['open_weighted']); ?></div>
        <div class="stat-label">Weighted value (value &times; probability)</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?php echo $money($summary['won_total']); ?></div>
        <div class="stat-label">Closed won</div>
    </div>
</div>

<div class="filters">
    <form method="get" action="<?php echo e(url('deals/index')); ?>" class="filter-form flex-wrap">
        <input type="hidden" name="view" value="<?php echo e($mode); ?>">
        <input type="search" name="search" class="form-control" placeholder="Search deal title" value="<?php echo e($fv('search')); ?>">
        <?php if ($mode === 'table'): ?>
        <select name="stage" class="form-control" aria-label="Stage">
            <option value="">All stages</option>
            <?php foreach ($stages as $value => $label): ?>
                <option value="<?php echo e($value); ?>"<?php echo $fv('stage') === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="assigned_to" class="form-control" aria-label="Assigned to">
            <option value="">Everyone</option>
            <?php foreach ($agents as $agent): ?>
                <option value="<?php echo (int)$agent['id']; ?>"<?php echo $fv('assigned_to') === (string)$agent['id'] ? ' selected' : ''; ?>><?php echo e($agent['first_name'] . ' ' . $agent['last_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if (!empty($filters)): ?>
            <a href="<?php echo e(url('deals/index') . '?view=' . $mode); ?>" class="btn btn-outline">Reset</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($mode === 'board'): ?>
    <?php if ($canEdit): ?>
        <p class="small text-muted mb-1">Drag a card to another column, or use the stage menu on the card, to move a deal.</p>
    <?php endif; ?>
    <div class="kanban" id="deal-board">
        <?php foreach ($pipeline as $stage => $column): ?>
        <div class="kanban-column" data-stage="<?php echo e($stage); ?>">
            <h3>
                <span><?php echo e($column['label']); ?></span>
                <span class="badge badge-secondary"><?php echo (int)$column['count']; ?></span>
            </h3>
            <div class="small text-muted mb-1">
                <?php echo $money($column['total']); ?>
                <?php if ($stage !== 'closed_won' && $stage !== 'closed_lost'): ?>
                    &middot; weighted <?php echo $money($column['weighted']); ?>
                <?php endif; ?>
            </div>
            <div class="kanban-dropzone" style="min-height: 60px;">
                <?php foreach ($column['deals'] as $deal): ?>
                <div class="kanban-card" data-deal-id="<?php echo (int)$deal['id']; ?>"<?php echo $canEdit ? ' draggable="true"' : ''; ?>>
                    <a href="<?php echo e(url('deals/view/' . (int)$deal['id'])); ?>"><?php echo e($deal['title']); ?></a>
                    <div class="small"><strong><?php echo $money($deal['deal_value']); ?></strong> <span class="text-muted">&middot; <?php echo (int)$deal['probability']; ?>%</span></div>
                    <?php if (!empty($deal['client_name']) && trim($deal['client_name']) !== ''): ?>
                        <div class="small text-muted">Client: <?php echo e(trim($deal['client_name'])); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($deal['property_title'])): ?>
                        <div class="small text-muted">Property: <?php echo e($deal['property_title']); ?></div>
                    <?php endif; ?>
                    <div class="small text-muted"><?php echo !empty($deal['agent_name']) ? e($deal['agent_name']) : 'Unassigned'; ?><?php if (!empty($deal['expected_close_date'])): ?> &middot; <?php echo e(View::formatDate($deal['expected_close_date'], 'M j')); ?><?php endif; ?></div>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?php echo e(url('deals/updateStage/' . (int)$deal['id'])); ?>" class="mt-1 deal-stage-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="return" value="board">
                        <select name="stage" class="form-control" style="padding: 2px 4px; font-size: 12px;" aria-label="Move to stage" onchange="this.form.submit()">
                            <?php foreach ($stages as $value => $label): ?>
                                <option value="<?php echo e($value); ?>"<?php echo $value === $stage ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn btn-sm btn-outline mt-1">Move</button></noscript>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($canEdit): ?>
    <script>
    (function () {
        var board = document.getElementById('deal-board');
        if (!board) { return; }
        var dragged = null;

        board.addEventListener('dragstart', function (e) {
            var card = e.target.closest ? e.target.closest('.kanban-card') : null;
            if (!card) { return; }
            dragged = card;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.getAttribute('data-deal-id'));
            card.style.opacity = '0.5';
        });
        board.addEventListener('dragend', function () {
            if (dragged) { dragged.style.opacity = ''; }
            dragged = null;
        });
        board.addEventListener('dragover', function (e) {
            if (dragged && e.target.closest('.kanban-column')) { e.preventDefault(); }
        });
        board.addEventListener('drop', function (e) {
            var column = e.target.closest('.kanban-column');
            if (!column || !dragged) { return; }
            e.preventDefault();
            var stage = column.getAttribute('data-stage');
            var card = dragged;
            if (card.closest('.kanban-column') === column) { return; }
            column.querySelector('.kanban-dropzone').appendChild(card);
            fetch(BASE_URL + '/deals/updateStage/' + card.getAttribute('data-deal-id'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ stage: stage })
            }).then(function (res) {
                return res.json().then(function (data) { return { ok: res.ok, data: data }; });
            }).then(function (r) {
                if (!r.ok || !r.data.success) { throw new Error(r.data.message || 'Update failed'); }
                window.location.reload(); // refresh column totals
            }).catch(function (err) {
                alert('Could not move the deal: ' + err.message);
                window.location.reload();
            });
        });
    })();
    </script>
    <?php endif; ?>

<?php else: ?>
    <div class="card">
        <?php if (empty($deals)): ?>
            <div class="empty-state">
                <h3>No deals found</h3>
                <p><?php echo !empty($filters) ? 'Try changing or resetting the filters.' : 'Create your first deal to start tracking the pipeline.'; ?></p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Deal</th><th>Client</th><th>Property</th><th>Stage</th>
                        <th class="text-right">Value</th><th class="text-right">Weighted</th>
                        <th>Expected close</th><th>Assigned</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deals as $deal): ?>
                    <tr>
                        <td><a href="<?php echo e(url('deals/view/' . (int)$deal['id'])); ?>"><strong><?php echo e($deal['title']); ?></strong></a></td>
                        <td><?php echo e(trim((string)($deal['client_name'] ?? ''))); ?></td>
                        <td><?php echo e($deal['property_title'] ?? ''); ?></td>
                        <td><span class="badge <?php echo e(Deal::stageBadgeClass($deal['stage'])); ?>"><?php echo e(isset($stages[$deal['stage']]) ? $stages[$deal['stage']] : (string)$deal['stage']); ?></span></td>
                        <td class="text-right"><?php echo $money($deal['deal_value']); ?></td>
                        <td class="text-right"><?php echo $money((float)$deal['deal_value'] * (int)$deal['probability'] / 100); ?> <span class="small text-muted">(<?php echo (int)$deal['probability']; ?>%)</span></td>
                        <td><?php echo e(View::formatDate($deal['expected_close_date'], 'M j, Y')); ?></td>
                        <td><?php echo e($deal['agent_name'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($currentPage > 1): ?><a class="page-link" href="<?php echo e($pageUrl($currentPage - 1)); ?>">&laquo; Previous</a><?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a class="page-link<?php echo $i === (int)$currentPage ? ' active' : ''; ?>" href="<?php echo e($pageUrl($i)); ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($currentPage < $totalPages): ?><a class="page-link" href="<?php echo e($pageUrl($currentPage + 1)); ?>">Next &raquo;</a><?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

<?php
// FILE: /app/views/scoring/test.php
require APP_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('scoring/index')); ?>">Lead Scoring</a> / Test rules</div>
        <h1>Test Scoring Rules</h1>
        <p class="text-muted">Evaluate the active rules against a lead without saving anything.</p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="GET" action="<?php echo e(url('scoring/test')); ?>" class="filter-form">
            <div class="form-group">
                <label for="lead_id">Lead</label>
                <select id="lead_id" name="lead_id" class="form-control">
                    <option value="">Select a lead&hellip;</option>
                    <?php foreach ($leads as $lead): ?>
                        <option value="<?php echo (int)$lead['id']; ?>" <?php echo $leadId === (int)$lead['id'] ? 'selected' : ''; ?>>
                            #<?php echo (int)$lead['id']; ?> &ndash; <?php echo e(trim($lead['first_name'] . ' ' . $lead['last_name'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Test</button>
        </form>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error mt-2"><?php echo e($error); ?></div>
<?php endif; ?>

<?php if ($result): ?>
    <?php $lead = $result['lead']; ?>
    <div class="stats-grid mt-2">
        <div class="stat-card">
            <div class="stat-number"><?php echo (int)$result['total_score']; ?></div>
            <div class="stat-label">Calculated score (raw <?php echo (int)$result['raw_score']; ?>)</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><span class="grade grade-<?php echo e($result['grade']); ?>"><?php echo e($result['grade']); ?></span></div>
            <div class="stat-label">Calculated grade</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">
                <?php if ($current): ?>
                    <?php echo (int)$current['score']; ?> <span class="grade grade-<?php echo e($current['grade']); ?>"><?php echo e($current['grade']); ?></span>
                <?php else: ?>-<?php endif; ?>
            </div>
            <div class="stat-label">Stored score</div>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header">
            <h3>
                <a href="<?php echo e(url('leads/view/' . (int)$lead['id'])); ?>"><?php echo e(trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''))); ?></a>
                <span class="small text-muted">#<?php echo (int)$lead['id']; ?></span>
            </h3>
        </div>
        <div class="card-body">
            <dl class="detail-list">
                <?php foreach ($fields as $key => $label): ?>
                    <dt><?php echo e($label); ?></dt>
                    <dd><?php echo isset($lead[$key]) && trim((string)$lead[$key]) !== '' ? e($lead[$key]) : '<span class="text-muted">(empty)</span>'; ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header"><h3>Rule results</h3></div>
        <div class="card-body">
            <?php if (empty($result['rules'])): ?>
                <p class="text-muted">There are no active rules. <a href="<?php echo e(url('scoring/create')); ?>">Create one</a>.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Rule</th><th>Result</th><th>Points</th><th>Reason</th></tr></thead>
                        <tbody>
                        <?php foreach ($result['rules'] as $row): ?>
                            <tr>
                                <td><a href="<?php echo e(url('scoring/edit/' . (int)$row['rule']['id'])); ?>"><?php echo e($row['rule']['name']); ?></a></td>
                                <td>
                                    <?php if ($row['matched']): ?>
                                        <span class="badge badge-success">Matched</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No match</span>
                                    <?php endif; ?>
                                </td>
                                <td class="<?php echo $row['points_awarded'] < 0 ? 'text-danger' : ''; ?>">
                                    <?php echo $row['matched'] ? ((int)$row['points_awarded'] > 0 ? '+' : '') . (int)$row['points_awarded'] : '0'; ?>
                                    <span class="small text-muted">/ <?php echo (int)$row['rule']['points']; ?></span>
                                </td>
                                <td class="small"><?php echo e($row['reason']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

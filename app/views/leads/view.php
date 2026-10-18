<?php
// FILE: /app/views/leads/view.php
require APP_PATH . '/views/layouts/header.php';

$leadId = (int)$lead['id'];
$leadName = trim($lead['first_name'] . ' ' . (string)$lead['last_name']);
$priorityBadges = array('high' => 'badge-danger', 'medium' => 'badge-warning', 'low' => 'badge-info', 'urgent' => 'badge-danger');
$budget = '-';
if ($lead['budget_min'] !== null || $lead['budget_max'] !== null) {
    $budget = ($lead['budget_min'] !== null ? View::formatCurrency($lead['budget_min']) : '?')
            . ' - ' . ($lead['budget_max'] !== null ? View::formatCurrency($lead['budget_max']) : '?');
}
$scoreValue = $leadScore ? max(0, min(100, (int)$leadScore['score'])) : 0;
$grade = $leadScore && !empty($leadScore['grade']) ? $leadScore['grade'] : null;
$breakdown = $leadScore && isset($leadScore['breakdown']) && is_array($leadScore['breakdown']) ? $leadScore['breakdown'] : array();
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('leads/index')); ?>">Leads</a> / <?php echo e($leadName); ?></div>
        <h1>
            <?php echo e($leadName); ?>
            <?php if ($grade): ?><span class="grade grade-<?php echo e($grade); ?>" title="Lead grade"><?php echo e($grade); ?></span><?php endif; ?>
            <?php echo View::statusBadge($lead['status']); ?>
        </h1>
    </div>
    <div class="header-actions">
        <?php if (can('leads.edit')): ?>
            <a href="<?php echo e(url('leads/edit/' . $leadId)); ?>" class="btn btn-primary">Edit</a>
        <?php endif; ?>
        <?php if (!empty($convertedClient)): ?>
            <a href="<?php echo e(url('clients/view/' . (int)$convertedClient['id'])); ?>" class="btn btn-success">View Client</a>
        <?php elseif ($lead['status'] !== 'lost' && can('clients.create') && can('leads.edit')): ?>
            <form method="POST" action="<?php echo e(url('leads/convert/' . $leadId)); ?>" class="inline-form"
                  onsubmit="return confirm('Convert this lead to a client? The lead will be marked as won.');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-success">Convert to Client</button>
            </form>
        <?php endif; ?>
        <?php if (can('leads.delete')): ?>
            <form method="POST" action="<?php echo e(url('leads/delete/' . $leadId)); ?>" class="inline-form"
                  onsubmit="return confirm('Delete this lead? This cannot be undone.');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        <?php endif; ?>
        <a href="<?php echo e(url('leads/index')); ?>" class="btn btn-secondary">Back to List</a>
    </div>
</div>

<div class="layout-main-side">
    <div>
        <div class="card mb-2">
            <div class="card-header"><h2>Contact Information</h2></div>
            <div class="card-body">
                <dl class="detail-list">
                    <dt>Email</dt>
                    <dd><?php if (!empty($lead['email'])): ?><a href="mailto:<?php echo e($lead['email']); ?>"><?php echo e($lead['email']); ?></a><?php else: ?>-<?php endif; ?></dd>
                    <dt>Phone</dt>
                    <dd><?php if (!empty($lead['phone'])): ?><a href="tel:<?php echo e($lead['phone']); ?>"><?php echo e($lead['phone']); ?></a><?php else: ?>-<?php endif; ?></dd>
                    <dt>Source</dt>
                    <dd><?php echo e($lead['source'] ?? '-'); ?></dd>
                    <dt>Interest Type</dt>
                    <dd><?php echo $lead['interest_type'] ? e(ucfirst($lead['interest_type'])) : '-'; ?></dd>
                    <dt>Budget Range</dt>
                    <dd><?php echo e($budget); ?></dd>
                    <dt>Assigned To</dt>
                    <dd><?php echo e($lead['assigned_to_name'] ?? 'Unassigned'); ?></dd>
                    <dt>Tags</dt>
                    <dd>
                        <?php if (!empty($tags)): ?>
                            <?php foreach ($tags as $tag): ?>
                                <a href="<?php echo e(url('leads/index') . '?tag=' . rawurlencode($tag)); ?>" class="badge badge-secondary"><?php echo e($tag); ?></a>
                            <?php endforeach; ?>
                        <?php else: ?>-<?php endif; ?>
                    </dd>
                    <dt>Created</dt>
                    <dd><?php echo View::formatDate($lead['created_at']); ?></dd>
                    <dt>Last Updated</dt>
                    <dd><?php echo View::formatDate($lead['updated_at']); ?></dd>
                </dl>
                <?php if (!empty($lead['notes'])): ?>
                    <h3 class="mt-2">Notes</h3>
                    <p><?php echo nl2br(e($lead['notes'])); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-2">
            <div class="card-header d-flex justify-between align-center">
                <h2>Tasks</h2>
                <?php if (can('tasks.create')): ?>
                    <a href="<?php echo e(url('tasks/create') . '?related_to_type=lead&related_to_id=' . $leadId); ?>" class="btn btn-sm btn-primary">Add Task</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($tasks)): ?>
                    <table class="table">
                        <thead><tr><th>Task</th><th>Due</th><th>Priority</th><th>Status</th><th>Assigned To</th></tr></thead>
                        <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td><a href="<?php echo e(url('tasks/view/' . (int)$task['id'])); ?>"><?php echo e($task['title']); ?></a></td>
                                <td class="<?php echo !empty($task['is_overdue']) ? 'text-danger' : ''; ?>"><?php echo View::formatDate($task['due_date']); ?></td>
                                <td><span class="badge <?php echo isset($priorityBadges[$task['priority']]) ? $priorityBadges[$task['priority']] : 'badge-secondary'; ?>"><?php echo e(ucfirst($task['priority'])); ?></span></td>
                                <td><?php echo View::statusBadge($task['status']); ?></td>
                                <td><?php echo e($task['assigned_to_name'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">No tasks for this lead.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-2">
            <div class="card-header"><h2>Activities</h2></div>
            <div class="card-body">
                <?php if (!empty($activities)): ?>
                    <ul class="timeline">
                        <?php foreach ($activities as $activity): ?>
                        <li>
                            <strong><?php echo e($activity['subject']); ?></strong>
                            <div class="timeline-meta">
                                <?php echo e(ucfirst($activity['activity_type'])); ?>
                                <?php if (!empty($activity['user_name'])): ?> &middot; <?php echo e($activity['user_name']); ?><?php endif; ?>
                                &middot; <?php echo View::formatDate($activity['created_at']); ?>
                            </div>
                            <?php if (!empty($activity['description'])): ?>
                                <p><?php echo nl2br(e($activity['description'])); ?></p>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted">No activities recorded.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-2">
            <div class="card-header"><h2>Attachments</h2></div>
            <div class="card-body">
                <?php if (!empty($attachments)): ?>
                    <ul class="task-list">
                        <?php foreach ($attachments as $attachment): ?>
                        <li>
                            <div>
                                <a href="<?php echo e(url('upload/download/' . (int)$attachment['id'])); ?>"><?php echo e($attachment['file_name']); ?></a>
                                <small>(<?php echo number_format((int)$attachment['file_size'] / 1024, 2); ?> KB)</small>
                                <br><small class="text-muted">Uploaded by <?php echo e($attachment['uploaded_by'] ?? 'unknown'); ?></small>
                            </div>
                            <span class="task-due"><?php echo View::formatDate($attachment['created_at']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted">No attachments.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <aside>
        <div class="card mb-2">
            <div class="card-header d-flex justify-between align-center">
                <h2>Lead Score</h2>
                <?php if (can('leads.edit')): ?>
                    <form method="POST" action="<?php echo e(url('leads/rescore/' . $leadId)); ?>" class="inline-form">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-sm btn-outline" title="Recalculate score">Recalculate</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($scoringError): ?>
                    <p class="text-muted">Lead scoring is temporarily unavailable.</p>
                <?php elseif (!$leadScore): ?>
                    <p class="text-muted">This lead has not been scored yet.</p>
                <?php else: ?>
                    <div class="d-flex align-center gap-2 mb-1">
                        <span class="grade grade-<?php echo e($grade ? $grade : 'F'); ?>" style="font-size: 1.5rem;"><?php echo e($grade ? $grade : '-'); ?></span>
                        <div style="flex: 1;">
                            <strong><?php echo $scoreValue; ?></strong><span class="text-muted">/100</span>
                            <div class="progress mt-1"><div class="progress-bar" style="width: <?php echo $scoreValue; ?>%;"></div></div>
                        </div>
                    </div>
                    <?php if (!empty($leadScore['last_calculated_at'])): ?>
                        <p class="small text-muted">Calculated <?php echo View::formatDate($leadScore['last_calculated_at']); ?></p>
                    <?php endif; ?>

                    <h3 class="mt-2">Score Breakdown</h3>
                    <?php if (!empty($breakdown)): ?>
                        <table class="table">
                            <tbody>
                            <?php foreach ($breakdown as $item): ?>
                                <?php $points = isset($item['points']) ? (int)$item['points'] : 0; ?>
                                <tr>
                                    <td><?php echo e(isset($item['rule_name']) ? $item['rule_name'] : 'Rule'); ?></td>
                                    <td class="text-right <?php echo $points >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo ($points >= 0 ? '+' : '') . $points; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-muted small">No scoring rules matched this lead.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($recommendations)): ?>
        <div class="card mb-2">
            <div class="card-header"><h2>Recommended Actions</h2></div>
            <div class="card-body">
                <ul class="task-list">
                    <?php foreach ($recommendations as $rec): ?>
                        <?php $prio = isset($rec['priority']) ? (string)$rec['priority'] : 'low'; ?>
                        <li>
                            <span><?php echo e(isset($rec['message']) ? $rec['message'] : ''); ?></span>
                            <span class="badge <?php echo isset($priorityBadges[$prio]) ? $priorityBadges[$prio] : 'badge-secondary'; ?>"><?php echo e(ucfirst($prio)); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($scoreHistory)): ?>
        <div class="card mb-2">
            <div class="card-header"><h2>Score History</h2></div>
            <div class="card-body">
                <ul class="timeline">
                    <?php foreach ($scoreHistory as $h): ?>
                        <?php $delta = (int)$h['new_score'] - (int)$h['old_score']; ?>
                        <li>
                            <strong><?php echo (int)$h['old_score']; ?> &rarr; <?php echo (int)$h['new_score']; ?></strong>
                            <span class="<?php echo $delta >= 0 ? 'text-success' : 'text-danger'; ?>">(<?php echo ($delta >= 0 ? '+' : '') . $delta; ?>)</span>
                            <?php if (!empty($h['new_grade'])): ?>
                                <span class="grade grade-<?php echo e($h['new_grade']); ?>"><?php echo e($h['new_grade']); ?></span>
                            <?php endif; ?>
                            <div class="timeline-meta">
                                <?php echo View::formatDate($h['created_at']); ?>
                                <?php if (!empty($h['changed_by'])): ?> &middot; <?php echo e($h['changed_by']); ?><?php endif; ?>
                            </div>
                            <?php if (!empty($h['change_reason'])): ?>
                                <div class="small text-muted"><?php echo e($h['change_reason']); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </aside>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

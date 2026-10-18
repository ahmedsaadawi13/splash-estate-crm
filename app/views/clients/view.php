<?php
// FILE: /app/views/clients/view.php
require APP_PATH . '/views/layouts/header.php';
$clientId = (int)$client['id'];
$clientName = trim($client['first_name'] . ' ' . (string)$client['last_name']);
$addressParts = array_filter(array($client['address'], $client['city'], $client['state'], $client['zip']), function ($v) { return $v !== null && $v !== ''; });
$priorityBadges = array('urgent' => 'badge-danger', 'high' => 'badge-danger', 'medium' => 'badge-warning', 'low' => 'badge-info');
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('clients/index')); ?>">Clients</a> / <?php echo e($clientName); ?></div>
        <h1><?php echo e($clientName); ?> <?php echo View::statusBadge($client['status']); ?></h1>
    </div>
    <div class="header-actions">
        <?php if (can('clients.edit')): ?>
            <a href="<?php echo e(url('clients/edit/' . $clientId)); ?>" class="btn btn-primary">Edit</a>
        <?php endif; ?>
        <?php if (can('clients.delete')): ?>
            <form method="POST" action="<?php echo e(url('clients/delete/' . $clientId)); ?>" class="inline-form"
                  onsubmit="return confirm('Delete this client? This cannot be undone.');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        <?php endif; ?>
        <a href="<?php echo e(url('clients/index')); ?>" class="btn btn-secondary">Back to List</a>
    </div>
</div>

<div class="layout-main-side">
    <div>
        <div class="card mb-2">
            <div class="card-header"><h2>Contact Information</h2></div>
            <div class="card-body">
                <dl class="detail-list">
                    <dt>Type</dt><dd><?php echo e(ucfirst((string)$client['client_type'])); ?></dd>
                    <dt>Email</dt>
                    <dd><?php if (!empty($client['email'])): ?><a href="mailto:<?php echo e($client['email']); ?>"><?php echo e($client['email']); ?></a><?php else: ?>-<?php endif; ?></dd>
                    <dt>Phone</dt>
                    <dd><?php if (!empty($client['phone'])): ?><a href="tel:<?php echo e($client['phone']); ?>"><?php echo e($client['phone']); ?></a><?php else: ?>-<?php endif; ?></dd>
                    <dt>Secondary Phone</dt><dd><?php echo e($client['phone_secondary'] ?? '-'); ?></dd>
                    <dt>Address</dt><dd><?php echo !empty($addressParts) ? nl2br(e(implode(', ', $addressParts))) : '-'; ?></dd>
                    <dt>Converted From</dt>
                    <dd>
                        <?php if (!empty($client['lead_id'])): ?>
                            <a href="<?php echo e(url('leads/view/' . (int)$client['lead_id'])); ?>"><?php echo e(trim((string)$client['lead_name']) !== '' ? $client['lead_name'] : 'Lead #' . (int)$client['lead_id']); ?></a>
                            <?php if (!empty($client['lead_source'])): ?><small class="text-muted">(<?php echo e($client['lead_source']); ?>)</small><?php endif; ?>
                        <?php else: ?>-<?php endif; ?>
                    </dd>
                    <dt>Created</dt><dd><?php echo View::formatDate($client['created_at']); ?></dd>
                    <dt>Last Updated</dt><dd><?php echo View::formatDate($client['updated_at']); ?></dd>
                </dl>
                <?php if (!empty($client['notes'])): ?>
                    <h3 class="mt-2">Notes</h3>
                    <p><?php echo nl2br(e($client['notes'])); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-2">
            <div class="card-header"><h2>Deals</h2></div>
            <div class="card-body">
                <?php if (!empty($deals)): ?>
                    <table class="table">
                        <thead><tr><th>Deal</th><th>Property</th><th>Stage</th><th class="text-right">Value</th><th>Expected Close</th></tr></thead>
                        <tbody>
                        <?php foreach ($deals as $deal): ?>
                            <tr>
                                <td><a href="<?php echo e(url('deals/view/' . (int)$deal['id'])); ?>"><?php echo e($deal['title']); ?></a></td>
                                <td><?php echo e($deal['property_title'] ?? '-'); ?></td>
                                <td><span class="badge badge-info"><?php echo e(ucwords(str_replace('_', ' ', (string)$deal['stage']))); ?></span></td>
                                <td class="text-right"><?php echo $deal['deal_value'] !== null ? View::formatCurrency($deal['deal_value']) : '-'; ?></td>
                                <td><?php echo View::formatDate($deal['expected_close_date'], 'Y-m-d'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">No deals for this client.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-2">
            <div class="card-header d-flex justify-between align-center">
                <h2>Tasks</h2>
                <?php if (can('tasks.create')): ?>
                    <a href="<?php echo e(url('tasks/create') . '?related_to_type=client&related_to_id=' . $clientId); ?>" class="btn btn-sm btn-primary">Add Task</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($tasks)): ?>
                    <table class="table">
                        <thead><tr><th>Task</th><th>Due</th><th>Priority</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td><a href="<?php echo e(url('tasks/view/' . (int)$task['id'])); ?>"><?php echo e($task['title']); ?></a></td>
                                <td class="<?php echo !empty($task['is_overdue']) ? 'text-danger' : ''; ?>"><?php echo View::formatDate($task['due_date']); ?></td>
                                <td><span class="badge <?php echo isset($priorityBadges[$task['priority']]) ? $priorityBadges[$task['priority']] : 'badge-secondary'; ?>"><?php echo e(ucfirst($task['priority'])); ?></span></td>
                                <td><?php echo View::statusBadge($task['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">No tasks for this client.</p>
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
                            <?php if (!empty($activity['description'])): ?><p><?php echo nl2br(e($activity['description'])); ?></p><?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted">No activities recorded.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <aside>
        <div class="card mb-2">
            <div class="card-header"><h2>Owned Properties</h2></div>
            <div class="card-body">
                <?php if (!empty($properties)): ?>
                    <ul class="task-list">
                        <?php foreach ($properties as $property): ?>
                        <li>
                            <a href="<?php echo e(url('properties/view/' . (int)$property['id'])); ?>"><?php echo e($property['title']); ?></a>
                            <span class="task-due"><?php echo e($property['city'] ?? ''); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted">No properties owned.</p>
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
                            <a href="<?php echo e(url('upload/download/' . (int)$attachment['id'])); ?>"><?php echo e($attachment['file_name']); ?></a>
                            <span class="task-due"><?php echo View::formatDate($attachment['created_at']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted">No attachments.</p>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

<?php
// FILE: /app/views/webhooks/_log_table.php
// Delivery log table. Expects $logs; $showWebhook (bool) adds a webhook column.
$logBadges = array('success' => 'badge-success', 'failed' => 'badge-danger', 'pending' => 'badge-warning', 'retrying' => 'badge-secondary');
$logLabels = array('success' => 'Delivered', 'failed' => 'Failed', 'pending' => 'Pending', 'retrying' => 'Retried');
$showWebhook = !empty($showWebhook);
?>
<?php if (empty($logs)): ?>
    <div class="card-body empty-state">
        <p class="text-muted">No deliveries yet.</p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Time</th>
                    <?php if ($showWebhook): ?><th>Webhook</th><?php endif; ?>
                    <th>Event</th>
                    <th>Status</th>
                    <th>Response</th>
                    <th class="text-right">Attempt</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php $status = isset($logBadges[$log['status']]) ? $log['status'] : 'pending'; ?>
                    <tr>
                        <td class="small"><?php echo e(View::formatDate($log['created_at'], 'Y-m-d H:i:s')); ?></td>
                        <?php if ($showWebhook): ?>
                            <td class="small"><a href="<?php echo e(url('webhooks/view/' . (int)$log['webhook_id'])); ?>"><?php echo e($log['webhook_name']); ?></a></td>
                        <?php endif; ?>
                        <td><code><?php echo e($log['event_type']); ?></code></td>
                        <td>
                            <span class="badge <?php echo $logBadges[$status]; ?>"><?php echo e($logLabels[$status]); ?></span>
                        </td>
                        <td class="small">
                            <?php if ($log['response_code'] !== null && $log['response_code'] !== ''): ?>
                                HTTP <?php echo (int)$log['response_code']; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                            <?php if (!empty($log['error_message'])): ?>
                                <div class="text-danger"><?php echo e(View::truncate($log['error_message'], 120)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right small"><?php echo (int)$log['attempt']; ?></td>
                        <td class="small">
                            <details>
                                <summary>Payload</summary>
                                <pre><?php
                                    $decoded = json_decode((string)$log['payload'], true);
                                    echo e($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)$log['payload']);
                                ?></pre>
                                <?php if (!empty($log['response_body'])): ?>
                                    <strong>Response body</strong>
                                    <pre><?php echo e(View::truncate($log['response_body'], 2000)); ?></pre>
                                <?php endif; ?>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
// FILE: /app/views/subscription/invoices.php
require APP_PATH . '/views/layouts/header.php';

$invoiceBadges = array(
    'paid' => 'badge-success',
    'open' => 'badge-warning',
    'pending' => 'badge-warning',
    'draft' => 'badge-secondary',
    'overdue' => 'badge-danger',
    'void' => 'badge-danger',
    'uncollectible' => 'badge-danger',
    'cancelled' => 'badge-secondary'
);
$totalPaid = 0;
$paidCount = 0;
foreach ($invoices as $invoice) {
    if ($invoice['status'] === 'paid') {
        $totalPaid += $invoice['amount'];
        $paidCount++;
    }
}
?>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="<?php echo e(url('subscription/index')); ?>">Subscription</a> / Payment history</div>
        <h1>Payment History</h1>
    </div>
    <div class="header-actions">
        <a href="<?php echo e(url('subscription/index')); ?>" class="btn btn-secondary">&larr; Back to Subscription</a>
    </div>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-warning">Invoices could not be loaded from Stripe right now. Showing locally recorded invoices.</div>
<?php elseif ($demoMode): ?>
    <div class="alert alert-info">Demo mode: showing invoices recorded in the CRM. Stripe invoices appear here once Stripe is configured.</div>
<?php endif; ?>

<?php if (!empty($invoices)): ?>
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-number"><?php echo e(View::formatCurrency($totalPaid)); ?></div><div class="stat-label">Total paid</div></div>
        <div class="stat-card"><div class="stat-number"><?php echo count($invoices); ?></div><div class="stat-label">Invoices</div></div>
        <div class="stat-card"><div class="stat-number"><?php echo (int)$paidCount; ?></div><div class="stat-label">Paid invoices</div></div>
    </div>
<?php endif; ?>

<div class="card mt-2">
    <div class="card-header"><h2>Invoices</h2></div>
    <?php if (empty($invoices)): ?>
        <div class="card-body empty-state"><p class="text-muted">No payment history available yet.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><strong><?php echo e($invoice['number']); ?></strong></td>
                            <td><?php echo e(View::formatDate($invoice['date'], 'M j, Y')); ?></td>
                            <td><?php echo e($invoice['description']); ?></td>
                            <td class="text-right"><?php echo e(View::formatCurrency($invoice['amount'])); ?></td>
                            <td>
                                <span class="badge <?php echo isset($invoiceBadges[$invoice['status']]) ? $invoiceBadges[$invoice['status']] : 'badge-secondary'; ?>">
                                    <?php echo e(ucfirst((string)$invoice['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($invoice['pdf_url'] !== ''): ?>
                                    <a href="<?php echo e($invoice['pdf_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline">PDF</a>
                                <?php endif; ?>
                                <?php if ($invoice['hosted_url'] !== ''): ?>
                                    <a href="<?php echo e($invoice['hosted_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">View</a>
                                <?php endif; ?>
                                <?php if ($invoice['pdf_url'] === '' && $invoice['hosted_url'] === ''): ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

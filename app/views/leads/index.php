<?php
// FILE: /app/views/leads/index.php
require APP_PATH . '/views/layouts/header.php';

$filters = isset($filters) && is_array($filters) ? $filters : array();
$statusLabels = array('new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'proposal' => 'Proposal',
                      'negotiation' => 'Negotiation', 'won' => 'Won', 'lost' => 'Lost');

// Source filter options: defaults plus whatever the tenant actually uses
$sourceOptions = array();
foreach (array_merge(array('Website', 'Referral', 'Social Media', 'Advertisement', 'Walk-in', 'API', 'Other'), isset($sources) ? $sources : array()) as $s) {
    $sourceOptions[$s] = $s;
}

// Configure advanced filter fields for leads
$module = 'leads';
$currentFilters = $filters;
$filterFields = array(
    array('name' => 'search', 'label' => 'Search', 'type' => 'text', 'placeholder' => 'Name, email, phone, notes, tags...'),
    array('name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $statusLabels),
    array('name' => 'grade', 'label' => 'Score Grade', 'type' => 'select',
          'options' => array('A' => 'A (hot)', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'F' => 'F')),
    array('name' => 'source', 'label' => 'Source', 'type' => 'select', 'options' => $sourceOptions),
    array('name' => 'interest_type', 'label' => 'Interest Type', 'type' => 'select',
          'options' => array('buy' => 'Buy', 'sell' => 'Sell', 'rent' => 'Rent', 'lease' => 'Lease')),
    array('name' => 'assigned_to', 'label' => 'Assigned Agent', 'type' => 'select', 'options' => $agents),
    array('name' => 'tag', 'label' => 'Tag', 'type' => 'text', 'placeholder' => 'e.g. investor'),
    array('name' => 'budget', 'label' => 'Budget Range', 'type' => 'numericrange'),
    array('name' => 'created', 'label' => 'Created Date', 'type' => 'daterange')
);
$sortOptions = array(
    'l.created_at' => 'Created Date',
    'score' => 'Lead Score',
    'l.first_name' => 'First Name',
    'l.last_name' => 'Last Name',
    'l.status' => 'Status',
    'l.budget_min' => 'Budget'
);
// Export with every active filter (ExportService whitelists the keys it understands)
$exportQuery = http_build_query(array_diff_key($filters, array_flip(array('sort', 'direction'))));
?>

<div class="page-header">
    <h1>Leads <small class="text-muted">(<?php echo (int)$totalLeads; ?>)</small></h1>
    <div class="header-actions">
        <?php if (can('leads.export')): ?>
            <a href="<?php echo e(url('export/leadsCSV') . ($exportQuery !== '' ? '?' . $exportQuery : '')); ?>" class="btn btn-secondary">Export CSV</a>
            <a href="<?php echo e(url('export/leadsPDF') . ($exportQuery !== '' ? '?' . $exportQuery : '')); ?>" class="btn btn-secondary">Print / Save as PDF</a>
        <?php endif; ?>
        <?php if (can('leads.create')): ?>
            <a href="<?php echo e(url('leads/create')); ?>" class="btn btn-primary">Create Lead</a>
        <?php endif; ?>
    </div>
</div>

<?php require APP_PATH . '/views/partials/advanced_filters.php'; ?>

<?php
// Setup bulk operations
$entityType = 'leads';
$statusOptions = $statusLabels;
require APP_PATH . '/views/partials/bulk_operations.php';
?>

<?php if (!empty($filters['tag'])): ?>
    <p class="mb-1">Tagged <span class="badge badge-info"><?php echo e($filters['tag']); ?></span>
        <a href="<?php echo e(url('leads/index')); ?>" class="btn btn-link btn-sm">Clear</a></p>
<?php endif; ?>

<?php if (!empty($leads)): ?>
<div class="table-responsive">
<table class="table">
    <thead>
        <tr>
            <th><input type="checkbox" class="bulk-checkbox-header"></th>
            <th>Name</th>
            <th>Score</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Status</th>
            <th>Source</th>
            <th>Tags</th>
            <th>Assigned To</th>
            <th>Created</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($leads as $lead): ?>
        <?php $leadId = (int)$lead['id']; ?>
        <tr>
            <td><input type="checkbox" class="bulk-checkbox" value="<?php echo $leadId; ?>"></td>
            <td>
                <a href="<?php echo e(url('leads/view/' . $leadId)); ?>">
                    <?php echo e(trim($lead['first_name'] . ' ' . (string)$lead['last_name'])); ?>
                </a>
            </td>
            <td>
                <?php if ($lead['grade'] !== null && $lead['grade'] !== ''): ?>
                    <span class="grade grade-<?php echo e($lead['grade']); ?>" title="Lead grade"><?php echo e($lead['grade']); ?></span>
                    <small class="text-muted"><?php echo (int)$lead['score']; ?></small>
                <?php else: ?>
                    <span class="text-muted">&ndash;</span>
                <?php endif; ?>
            </td>
            <td><?php echo e($lead['email'] ?? ''); ?></td>
            <td><?php echo e($lead['phone'] ?? ''); ?></td>
            <td><?php echo View::statusBadge($lead['status'] ?? ''); ?></td>
            <td><?php echo e($lead['source'] ?? ''); ?></td>
            <td>
                <?php foreach (Lead::tagList($lead['tags']) as $tag): ?>
                    <a href="<?php echo e(url('leads/index') . '?tag=' . rawurlencode($tag)); ?>" class="badge badge-secondary"><?php echo e($tag); ?></a>
                <?php endforeach; ?>
            </td>
            <td><?php echo e($lead['assigned_to_name'] ?? ''); ?></td>
            <td><?php echo View::formatDate($lead['created_at']); ?></td>
            <td class="d-flex gap-1">
                <a href="<?php echo e(url('leads/view/' . $leadId)); ?>" class="btn btn-sm btn-outline">View</a>
                <?php if (can('leads.edit')): ?>
                    <a href="<?php echo e(url('leads/edit/' . $leadId)); ?>" class="btn btn-sm btn-outline">Edit</a>
                <?php endif; ?>
                <?php if (can('leads.delete')): ?>
                    <form method="POST" action="<?php echo e(url('leads/delete/' . $leadId)); ?>" class="inline-form"
                          onsubmit="return confirm('Delete this lead? This cannot be undone.');">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php
$paginationPath = 'leads/index';
$paginationQuery = $filters;
require APP_PATH . '/views/leads/_pagination.php';
?>

<?php else: ?>
<div class="empty-state">
    <p>No leads found.</p>
    <?php if (can('leads.create')): ?>
        <a href="<?php echo e(url('leads/create')); ?>" class="btn btn-primary">Create your first lead</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

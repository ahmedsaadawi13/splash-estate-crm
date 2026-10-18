<?php
// FILE: /app/views/clients/index.php
require APP_PATH . '/views/layouts/header.php';
$filters = isset($filters) && is_array($filters) ? $filters : array();
$f = function ($key) use ($filters) { return isset($filters[$key]) ? $filters[$key] : ''; };
?>

<div class="page-header">
    <h1>Clients <small class="text-muted">(<?php echo (int)$totalClients; ?>)</small></h1>
    <div class="header-actions">
        <?php if (can('clients.create')): ?>
            <a href="<?php echo e(url('clients/create')); ?>" class="btn btn-primary">Create Client</a>
        <?php endif; ?>
    </div>
</div>

<div class="filters">
    <form method="GET" action="<?php echo e(url('clients/index')); ?>" class="filter-form">
        <input type="text" name="search" placeholder="Search name, email, phone, city..." value="<?php echo e($f('search')); ?>" class="form-control">
        <select name="client_type" class="form-control">
            <option value="">All Types</option>
            <?php foreach (array('buyer' => 'Buyer', 'seller' => 'Seller', 'landlord' => 'Landlord', 'tenant' => 'Tenant') as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php echo $f('client_type') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-control">
            <option value="">All Statuses</option>
            <option value="active" <?php echo $f('status') === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $f('status') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <a href="<?php echo e(url('clients/index')); ?>" class="btn btn-link">Clear</a>
    </form>
</div>

<?php if (!empty($clients)): ?>
<div class="table-responsive">
<table class="table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Type</th>
            <th>City</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($clients as $client): ?>
        <?php $clientId = (int)$client['id']; ?>
        <tr>
            <td>
                <a href="<?php echo e(url('clients/view/' . $clientId)); ?>"><?php echo e(trim($client['first_name'] . ' ' . (string)$client['last_name'])); ?></a>
                <?php if (!empty($client['lead_id'])): ?><span class="badge badge-info" title="Converted from a lead">Lead</span><?php endif; ?>
            </td>
            <td><?php echo e($client['email'] ?? ''); ?></td>
            <td><?php echo e($client['phone'] ?? ''); ?></td>
            <td><?php echo e(ucfirst((string)$client['client_type'])); ?></td>
            <td><?php echo e($client['city'] ?? ''); ?></td>
            <td><?php echo View::statusBadge($client['status'] ?? ''); ?></td>
            <td><?php echo View::formatDate($client['created_at']); ?></td>
            <td class="d-flex gap-1">
                <a href="<?php echo e(url('clients/view/' . $clientId)); ?>" class="btn btn-sm btn-outline">View</a>
                <?php if (can('clients.edit')): ?>
                    <a href="<?php echo e(url('clients/edit/' . $clientId)); ?>" class="btn btn-sm btn-outline">Edit</a>
                <?php endif; ?>
                <?php if (can('clients.delete')): ?>
                    <form method="POST" action="<?php echo e(url('clients/delete/' . $clientId)); ?>" class="inline-form"
                          onsubmit="return confirm('Delete this client? This cannot be undone.');">
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
$paginationPath = 'clients/index';
$paginationQuery = $filters;
require APP_PATH . '/views/leads/_pagination.php';
?>

<?php else: ?>
<div class="empty-state"><p>No clients found.</p></div>
<?php endif; ?>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

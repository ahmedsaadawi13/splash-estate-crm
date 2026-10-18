<?php
// FILE: /app/views/properties/index.php
require APP_PATH . '/views/layouts/header.php';

$filters = isset($filters) ? $filters : array();
$fv = function ($key) use ($filters) {
    return isset($filters[$key]) ? (string)$filters[$key] : '';
};
$typeLabels = array('residential' => 'Residential', 'commercial' => 'Commercial', 'land' => 'Land');
$listingLabels = array('sale' => 'Sale', 'rent' => 'Rent', 'lease' => 'Lease');
$statusLabels = array('available' => 'Available', 'pending' => 'Pending', 'sold' => 'Sold', 'rented' => 'Rented', 'off_market' => 'Off Market');
$statusBadges = array('available' => 'badge-success', 'pending' => 'badge-warning', 'sold' => 'badge-info', 'rented' => 'badge-info', 'off_market' => 'badge-secondary');
$pageUrl = function ($page) use ($filters) {
    return url('properties/index') . '?' . http_build_query(array_merge($filters, array('page' => (int)$page)));
};
?>
<div class="page-header">
    <h1>Properties</h1>
    <div class="header-actions">
        <?php if (can('properties.create')): ?>
            <a href="<?php echo e(url('properties/create')); ?>" class="btn btn-primary">+ Add Property</a>
        <?php endif; ?>
    </div>
</div>

<div class="filters">
    <form method="get" action="<?php echo e(url('properties/index')); ?>" class="filter-form flex-wrap">
        <input type="search" name="search" class="form-control" placeholder="Search title, address, city, ZIP" value="<?php echo e($fv('search')); ?>" style="min-width: 200px;">
        <select name="property_type" class="form-control" aria-label="Property type">
            <option value="">All types</option>
            <?php foreach ($typeLabels as $value => $label): ?>
                <option value="<?php echo e($value); ?>"<?php echo $fv('property_type') === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="listing_type" class="form-control" aria-label="Listing type">
            <option value="">Any listing</option>
            <?php foreach ($listingLabels as $value => $label): ?>
                <option value="<?php echo e($value); ?>"<?php echo $fv('listing_type') === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-control" aria-label="Status">
            <option value="">Any status</option>
            <?php foreach ($statusLabels as $value => $label): ?>
                <option value="<?php echo e($value); ?>"<?php echo $fv('status') === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="city" class="form-control" aria-label="City">
            <option value="">All cities</option>
            <?php foreach ($cities as $city): ?>
                <option value="<?php echo e($city); ?>"<?php echo $fv('city') === (string)$city ? ' selected' : ''; ?>><?php echo e($city); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="min_price" class="form-control" min="0" step="1000" placeholder="Min price" value="<?php echo e($fv('min_price')); ?>" style="max-width: 130px;">
        <input type="number" name="max_price" class="form-control" min="0" step="1000" placeholder="Max price" value="<?php echo e($fv('max_price')); ?>" style="max-width: 130px;">
        <select name="bedrooms" class="form-control" aria-label="Bedrooms" style="max-width: 110px;">
            <option value="">Beds</option>
            <?php for ($b = 1; $b <= 6; $b++): ?>
                <option value="<?php echo $b; ?>"<?php echo $fv('bedrooms') === (string)$b ? ' selected' : ''; ?>><?php echo $b; ?>+</option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if (!empty($filters)): ?>
            <a href="<?php echo e(url('properties/index')); ?>" class="btn btn-outline">Reset</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2><?php echo (int)$totalProperties; ?> propert<?php echo (int)$totalProperties === 1 ? 'y' : 'ies'; ?></h2>
    </div>
    <?php if (empty($properties)): ?>
        <div class="empty-state">
            <h3>No properties found</h3>
            <p><?php echo !empty($filters) ? 'Try changing or resetting the filters.' : 'Add your first listing to get started.'; ?></p>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 80px;"></th>
                    <th>Property</th>
                    <th>Type</th>
                    <th class="text-right">Price</th>
                    <th>Beds / Baths</th>
                    <th>Status</th>
                    <th>Agent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($properties as $p): ?>
                <tr>
                    <td>
                        <?php if (!empty($p['primary_image_id'])): ?>
                            <img src="<?php echo e(url('propertygallery/image/' . (int)$p['primary_image_id'])); ?>" alt="" loading="lazy" style="width: 72px; height: 54px; object-fit: cover; border-radius: 4px; display: block;">
                        <?php else: ?>
                            <div style="width: 72px; height: 54px; border-radius: 4px; background: #ecf0f1;"></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo e(url('properties/view/' . (int)$p['id'])); ?>"><strong><?php echo e($p['title']); ?></strong></a><br>
                        <span class="small text-muted"><?php echo e($p['address'] . ', ' . $p['city'] . ', ' . $p['state'] . ' ' . $p['zip']); ?></span>
                    </td>
                    <td>
                        <?php echo e(isset($typeLabels[$p['property_type']]) ? $typeLabels[$p['property_type']] : (string)$p['property_type']); ?>
                        <?php if (!empty($p['sub_type'])): ?><span class="small text-muted">&middot; <?php echo e($p['sub_type']); ?></span><?php endif; ?><br>
                        <span class="badge badge-primary"><?php echo e(isset($listingLabels[$p['listing_type']]) ? $listingLabels[$p['listing_type']] : (string)$p['listing_type']); ?></span>
                    </td>
                    <td class="text-right">
                        <?php echo e(View::formatCurrency((float)$p['price'])); ?>
                        <?php if ($p['listing_type'] !== 'sale'): ?><span class="small text-muted">/mo</span><?php endif; ?>
                    </td>
                    <td><?php echo $p['bedrooms'] !== null ? (int)$p['bedrooms'] : '&ndash;'; ?> / <?php echo $p['bathrooms'] !== null ? e((string)(float)$p['bathrooms']) : '&ndash;'; ?></td>
                    <td><span class="badge <?php echo e(isset($statusBadges[$p['status']]) ? $statusBadges[$p['status']] : 'badge-secondary'); ?>"><?php echo e(isset($statusLabels[$p['status']]) ? $statusLabels[$p['status']] : (string)$p['status']); ?></span></td>
                    <td><?php echo e($p['agent_name'] ?? ''); ?></td>
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

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

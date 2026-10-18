<?php
// FILE: /app/views/properties/view.php
require APP_PATH . '/views/layouts/header.php';

require_once APP_PATH . '/models/Deal.php';
$propertyId = (int)$property['id'];
$typeLabels = array('residential' => 'Residential', 'commercial' => 'Commercial', 'land' => 'Land');
$listingLabels = array('sale' => 'For Sale', 'rent' => 'For Rent', 'lease' => 'For Lease');
$statusLabels = array('available' => 'Available', 'pending' => 'Pending', 'sold' => 'Sold', 'rented' => 'Rented', 'off_market' => 'Off Market');
$statusBadges = array('available' => 'badge-success', 'pending' => 'badge-warning', 'sold' => 'badge-info', 'rented' => 'badge-info', 'off_market' => 'badge-secondary');
$stageLabels = Deal::getStages();
$isRental = $property['listing_type'] !== 'sale';
$dash = '&ndash;';
?>
<div class="breadcrumb"><a href="<?php echo e(url('properties/index')); ?>">Properties</a> &rsaquo; <?php echo e($property['title']); ?></div>
<div class="page-header">
    <div>
        <h1><?php echo e($property['title']); ?></h1>
        <div class="text-muted"><?php echo e($property['address'] . ', ' . $property['city'] . ', ' . $property['state'] . ' ' . $property['zip']); ?></div>
    </div>
    <div class="header-actions">
        <?php if (can('deals.create')): ?>
            <a href="<?php echo e(url('deals/create') . '?property_id=' . $propertyId); ?>" class="btn btn-success">+ New Deal</a>
        <?php endif; ?>
        <?php if (can('properties.edit')): ?>
            <a href="<?php echo e(url('properties/edit/' . $propertyId)); ?>" class="btn btn-primary">Edit</a>
        <?php endif; ?>
        <?php if (can('properties.delete')): ?>
            <form method="post" action="<?php echo e(url('properties/delete/' . $propertyId)); ?>" class="inline-form" onsubmit="return confirm('Delete this property and all of its photos? This cannot be undone.');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        <?php endif; ?>
        <a href="<?php echo e(url('properties/index')); ?>" class="btn btn-secondary">Back to list</a>
    </div>
</div>

<div class="layout-main-side">
    <div>
        <?php require APP_PATH . '/views/partials/property_gallery.php'; ?>

        <div class="card">
            <div class="card-header"><h2>Details</h2></div>
            <div class="card-body">
                <dl class="detail-list">
                    <dt>Type</dt>
                    <dd><?php echo e(isset($typeLabels[$property['property_type']]) ? $typeLabels[$property['property_type']] : (string)$property['property_type']); ?><?php if (!empty($property['sub_type'])): ?> &middot; <?php echo e($property['sub_type']); ?><?php endif; ?></dd>
                    <dt>Bedrooms</dt>
                    <dd><?php echo $property['bedrooms'] !== null ? (int)$property['bedrooms'] : $dash; ?></dd>
                    <dt>Bathrooms</dt>
                    <dd><?php echo $property['bathrooms'] !== null ? e((string)(float)$property['bathrooms']) : $dash; ?></dd>
                    <dt>Living area</dt>
                    <dd><?php echo $property['square_feet'] !== null ? e(number_format((int)$property['square_feet'])) . ' sq ft' : $dash; ?></dd>
                    <dt>Lot size</dt>
                    <dd><?php echo $property['lot_size'] !== null ? e((string)(float)$property['lot_size']) . ' acres' : $dash; ?></dd>
                    <dt>Year built</dt>
                    <dd><?php echo $property['year_built'] !== null ? (int)$property['year_built'] : $dash; ?></dd>
                    <?php if ($property['price'] !== null && $property['square_feet'] && !$isRental): ?>
                        <dt>Price / sq ft</dt>
                        <dd><?php echo e(View::formatCurrency((float)$property['price'] / (int)$property['square_feet'])); ?></dd>
                    <?php endif; ?>
                    <dt>Listed</dt>
                    <dd><?php echo e(View::formatDate($property['created_at'], 'M j, Y')); ?></dd>
                    <dt>Last updated</dt>
                    <dd><?php echo e(View::formatDate($property['updated_at'], 'M j, Y H:i')); ?></dd>
                </dl>
                <?php if (!empty($property['description'])): ?>
                    <h3 class="mt-2 mb-1">Description</h3>
                    <p><?php echo nl2br(e($property['description'])); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (can('deals.view')): ?>
        <div class="card">
            <div class="card-header">
                <h2>Deals (<?php echo count($deals); ?>)</h2>
                <?php if (can('deals.create')): ?>
                    <a href="<?php echo e(url('deals/create') . '?property_id=' . $propertyId); ?>" class="btn btn-sm btn-outline">+ New Deal</a>
                <?php endif; ?>
            </div>
            <?php if (empty($deals)): ?>
                <div class="card-body text-muted">No deals for this property yet.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Deal</th><th>Client</th><th>Stage</th><th class="text-right">Value</th><th>Assigned</th></tr></thead>
                    <tbody>
                    <?php foreach ($deals as $deal): ?>
                        <tr>
                            <td><a href="<?php echo e(url('deals/view/' . (int)$deal['id'])); ?>"><?php echo e($deal['title']); ?></a></td>
                            <td><?php echo e($deal['client_name'] ?? ''); ?></td>
                            <td><span class="badge <?php echo e(Deal::stageBadgeClass($deal['stage'])); ?>"><?php echo e(isset($stageLabels[$deal['stage']]) ? $stageLabels[$deal['stage']] : (string)$deal['stage']); ?></span></td>
                            <td class="text-right"><?php echo e(View::formatCurrency((float)$deal['deal_value'])); ?></td>
                            <td><?php echo e($deal['agent_name'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (can('leads.view')): ?>
        <div class="card">
            <div class="card-header">
                <h2>Matching leads (<?php echo count($matchingLeads); ?>)</h2>
                <span class="small text-muted">
                    Interest: <?php echo $isRental ? 'rent / lease' : 'buy'; ?> &middot; budget includes <?php echo e(View::formatCurrency((float)$property['price'])); ?>
                </span>
            </div>
            <?php if (empty($matchingLeads)): ?>
                <div class="card-body text-muted">No open leads currently match this listing's price and type.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Lead</th><th>Interest</th><th>Budget</th><th>Status</th><th>Assigned</th></tr></thead>
                    <tbody>
                    <?php foreach ($matchingLeads as $lead): ?>
                        <tr>
                            <td>
                                <a href="<?php echo e(url('leads/view/' . (int)$lead['id'])); ?>"><?php echo e(trim($lead['first_name'] . ' ' . ($lead['last_name'] ?? ''))); ?></a>
                                <?php if (!empty($lead['email'])): ?><br><span class="small text-muted"><?php echo e($lead['email']); ?></span><?php endif; ?>
                            </td>
                            <td><?php echo e(ucfirst((string)$lead['interest_type'])); ?></td>
                            <td>
                                <?php echo $lead['budget_min'] !== null ? e(View::formatCurrency((float)$lead['budget_min'])) : 'Any'; ?>
                                &ndash;
                                <?php echo $lead['budget_max'] !== null ? e(View::formatCurrency((float)$lead['budget_max'])) : 'Any'; ?>
                            </td>
                            <td><?php echo View::statusBadge((string)$lead['status']); ?></td>
                            <td><?php echo e($lead['assigned_name'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <div class="card-body">
                <div class="stat-number"><?php echo e(View::formatCurrency((float)$property['price'])); ?><?php if ($isRental): ?><span class="small text-muted"> /month</span><?php endif; ?></div>
                <div class="mt-1 d-flex gap-1 flex-wrap">
                    <span class="badge <?php echo e(isset($statusBadges[$property['status']]) ? $statusBadges[$property['status']] : 'badge-secondary'); ?>"><?php echo e(isset($statusLabels[$property['status']]) ? $statusLabels[$property['status']] : (string)$property['status']); ?></span>
                    <span class="badge badge-primary"><?php echo e(isset($listingLabels[$property['listing_type']]) ? $listingLabels[$property['listing_type']] : (string)$property['listing_type']); ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Owner</h2></div>
            <div class="card-body">
                <?php if (!empty($property['owner_id']) && !empty($property['owner_name'])): ?>
                    <a href="<?php echo e(url('clients/view/' . (int)$property['owner_id'])); ?>"><strong><?php echo e(trim($property['owner_name'])); ?></strong></a>
                    <?php if (!empty($property['owner_email'])): ?><br><a href="mailto:<?php echo e($property['owner_email']); ?>"><?php echo e($property['owner_email']); ?></a><?php endif; ?>
                    <?php if (!empty($property['owner_phone'])): ?><br><a href="tel:<?php echo e($property['owner_phone']); ?>"><?php echo e($property['owner_phone']); ?></a><?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">No owner linked.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Listing agent</h2></div>
            <div class="card-body">
                <?php if (!empty($property['agent_name'])): ?>
                    <strong><?php echo e($property['agent_name']); ?></strong>
                    <?php if (!empty($property['agent_email'])): ?><br><a href="mailto:<?php echo e($property['agent_email']); ?>"><?php echo e($property['agent_email']); ?></a><?php endif; ?>
                    <?php if (!empty($property['agent_phone'])): ?><br><?php echo e($property['agent_phone']); ?><?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">Unassigned.</span>
                <?php endif; ?>
            </div>
        </div>

        <?php require APP_PATH . '/views/partials/property_map.php'; ?>

        <div class="card">
            <div class="card-header"><h2>Documents</h2></div>
            <div class="card-body">
                <?php if (empty($attachments)): ?>
                    <span class="text-muted">No documents attached.</span>
                <?php else: ?>
                    <ul class="task-list">
                        <?php foreach ($attachments as $attachment): ?>
                            <li>
                                <a href="<?php echo e(url('upload/download/' . (int)$attachment['id'])); ?>"><?php echo e($attachment['file_name']); ?></a>
                                <span class="task-due"><?php echo e(View::formatDate($attachment['created_at'], 'M j, Y')); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

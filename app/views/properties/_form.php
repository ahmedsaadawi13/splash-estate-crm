<?php
// FILE: /app/views/properties/_form.php
// Shared property form fields. Expects: $formData, $errors, $agents, $clients
$f = isset($formData) && is_array($formData) ? $formData : array();
$errors = isset($errors) && is_array($errors) ? $errors : array();
$val = function ($key) use ($f) {
    return isset($f[$key]) && $f[$key] !== null ? (string)$f[$key] : '';
};
$err = function ($key) use ($errors) {
    return isset($errors[$key]) ? '<span class="form-help text-danger">' . e($errors[$key]) . '</span>' : '';
};
$typeLabels = array('residential' => 'Residential', 'commercial' => 'Commercial', 'land' => 'Land');
$listingLabels = array('sale' => 'For Sale', 'rent' => 'For Rent', 'lease' => 'For Lease');
$statusLabels = array('available' => 'Available', 'pending' => 'Pending', 'sold' => 'Sold', 'rented' => 'Rented', 'off_market' => 'Off Market');
?>
<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-error"><?php echo e($errors['general']); ?></div>
<?php elseif (!empty($errors)): ?>
    <div class="alert alert-error">Please correct the highlighted fields.</div>
<?php endif; ?>

<?php echo csrf_field(); ?>

<div class="card">
    <div class="card-header"><h2>Listing</h2></div>
    <div class="card-body">
        <div class="form-group">
            <label for="title">Title <span class="required">*</span></label>
            <input type="text" id="title" name="title" class="form-control" maxlength="255" required value="<?php echo e($val('title')); ?>">
            <?php echo $err('title'); ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="property_type">Property type <span class="required">*</span></label>
                <select id="property_type" name="property_type" class="form-control" required>
                    <?php foreach ($typeLabels as $value => $label): ?>
                        <option value="<?php echo e($value); ?>"<?php echo $val('property_type') === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('property_type'); ?>
            </div>
            <div class="form-group">
                <label for="sub_type">Sub-type</label>
                <input type="text" id="sub_type" name="sub_type" class="form-control" maxlength="100" placeholder="House, Condo, Office..." value="<?php echo e($val('sub_type')); ?>">
            </div>
            <div class="form-group">
                <label for="listing_type">Listing type <span class="required">*</span></label>
                <select id="listing_type" name="listing_type" class="form-control" required>
                    <?php foreach ($listingLabels as $value => $label): ?>
                        <option value="<?php echo e($value); ?>"<?php echo $val('listing_type') === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('listing_type'); ?>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?php echo e($value); ?>"<?php echo ($val('status') === '' ? 'available' : $val('status')) === $value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('status'); ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="price">Price <span class="required">*</span></label>
                <input type="number" id="price" name="price" class="form-control" min="0" step="0.01" required value="<?php echo e($val('price')); ?>">
                <span class="form-help">Sale price, or monthly rent for rentals/leases.</span>
                <?php echo $err('price'); ?>
            </div>
            <div class="form-group">
                <label for="owner_id">Owner (client)</label>
                <select id="owner_id" name="owner_id" class="form-control">
                    <option value="">&mdash; None &mdash;</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?php echo (int)$client['id']; ?>"<?php echo $val('owner_id') === (string)$client['id'] ? ' selected' : ''; ?>><?php echo e(trim($client['first_name'] . ' ' . ($client['last_name'] ?? ''))); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('owner_id'); ?>
            </div>
            <div class="form-group">
                <label for="listing_agent_id">Listing agent</label>
                <select id="listing_agent_id" name="listing_agent_id" class="form-control">
                    <option value="">&mdash; Unassigned &mdash;</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo (int)$agent['id']; ?>"<?php echo $val('listing_agent_id') === (string)$agent['id'] ? ' selected' : ''; ?>><?php echo e($agent['first_name'] . ' ' . $agent['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo $err('listing_agent_id'); ?>
            </div>
        </div>
        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="5"><?php echo e($val('description')); ?></textarea>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Details</h2></div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label for="bedrooms">Bedrooms</label>
                <input type="number" id="bedrooms" name="bedrooms" class="form-control" min="0" step="1" value="<?php echo e($val('bedrooms')); ?>">
                <?php echo $err('bedrooms'); ?>
            </div>
            <div class="form-group">
                <label for="bathrooms">Bathrooms</label>
                <input type="number" id="bathrooms" name="bathrooms" class="form-control" min="0" step="0.5" value="<?php echo e($val('bathrooms')); ?>">
                <?php echo $err('bathrooms'); ?>
            </div>
            <div class="form-group">
                <label for="square_feet">Square feet</label>
                <input type="number" id="square_feet" name="square_feet" class="form-control" min="0" step="1" value="<?php echo e($val('square_feet')); ?>">
                <?php echo $err('square_feet'); ?>
            </div>
            <div class="form-group">
                <label for="lot_size">Lot size (acres)</label>
                <input type="number" id="lot_size" name="lot_size" class="form-control" min="0" step="0.01" value="<?php echo e($val('lot_size')); ?>">
                <?php echo $err('lot_size'); ?>
            </div>
            <div class="form-group">
                <label for="year_built">Year built</label>
                <input type="number" id="year_built" name="year_built" class="form-control" min="1600" max="<?php echo (int)date('Y') + 5; ?>" step="1" value="<?php echo e($val('year_built')); ?>">
                <?php echo $err('year_built'); ?>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Address</h2></div>
    <div class="card-body">
        <div class="form-group">
            <label for="address">Street address <span class="required">*</span></label>
            <input type="text" id="address" name="address" class="form-control" maxlength="255" required value="<?php echo e($val('address')); ?>">
            <?php echo $err('address'); ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="city">City <span class="required">*</span></label>
                <input type="text" id="city" name="city" class="form-control" maxlength="100" required value="<?php echo e($val('city')); ?>">
                <?php echo $err('city'); ?>
            </div>
            <div class="form-group">
                <label for="state">State <span class="required">*</span></label>
                <input type="text" id="state" name="state" class="form-control" maxlength="100" required value="<?php echo e($val('state')); ?>">
                <?php echo $err('state'); ?>
            </div>
            <div class="form-group">
                <label for="zip">ZIP <span class="required">*</span></label>
                <input type="text" id="zip" name="zip" class="form-control" maxlength="20" required value="<?php echo e($val('zip')); ?>">
                <?php echo $err('zip'); ?>
            </div>
        </div>
        <span class="form-help">The address is geocoded automatically for the map when Google Maps is configured.</span>
    </div>
</div>

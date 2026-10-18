<?php
// FILE: /app/views/subscription/index.php
require APP_PATH . '/views/layouts/header.php';

$limitLabel = function ($value) {
    return (int)$value === -1 ? 'Unlimited' : number_format((int)$value);
};
$usagePercent = function ($used, $limit) {
    $limit = (int)$limit;
    if ($limit === -1) {
        return 0;
    }
    if ($limit <= 0) {
        return 100;
    }
    return (int)min(100, round(($used / $limit) * 100));
};
$currentPlanId = $currentPlan ? (int)$currentPlan['id'] : 0;
$currentPrice = $currentPlan ? (float)$currentPlan['price'] : null;
?>

<div class="page-header">
    <div>
        <h1>Subscription &amp; Billing</h1>
        <p class="text-muted">Manage your plan, usage and payments</p>
    </div>
    <div class="header-actions">
        <a href="<?php echo e(url('subscription/invoices')); ?>" class="btn btn-secondary">Payment History</a>
    </div>
</div>

<?php if ($demoMode): ?>
    <div class="alert alert-warning">
        <strong>Demo mode:</strong> Stripe is not configured (<code>STRIPE_SECRET_KEY</code> is empty).
        Plan changes are recorded locally and no payment is taken.
    </div>
<?php endif; ?>

<?php if ($checkoutStatus === 'success'): ?>
    <div class="alert alert-success">Thank you! Your payment was received. Your plan is updated as soon as Stripe confirms the subscription (usually within seconds).</div>
<?php elseif ($checkoutStatus === 'cancelled'): ?>
    <div class="alert alert-info">Checkout was cancelled. Your plan has not changed.</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Current Plan</h2></div>
    <div class="card-body">
        <?php if ($currentPlan && $subscription): ?>
            <div class="grid-2">
                <div>
                    <h3 class="mb-1"><?php echo e($currentPlan['name']); ?> <?php echo View::statusBadge($subscription['status']); ?></h3>
                    <p class="stat-number" style="font-size:1.5rem;">
                        <?php echo e(View::formatCurrency($currentPlan['price'])); ?>
                        <span class="small text-muted">/ <?php echo e($currentPlan['billing_cycle'] === 'yearly' ? 'year' : 'month'); ?></span>
                    </p>
                    <p class="text-muted"><?php echo e($currentPlan['description'] ?? ''); ?></p>
                </div>
                <div>
                    <dl class="detail-list">
                        <dt>Started</dt>
                        <dd><?php echo e(View::formatDate($subscription['start_date'], 'F j, Y')); ?></dd>
                        <dt><?php echo !empty($subscription['auto_renew']) ? 'Renews' : 'Ends'; ?></dt>
                        <dd>
                            <?php echo !empty($subscription['end_date']) ? e(View::formatDate($subscription['end_date'], 'F j, Y')) : (!empty($subscription['auto_renew']) ? 'Automatically each period' : '—'); ?>
                            <?php if (empty($subscription['auto_renew'])): ?>
                                <span class="badge badge-warning">Will not renew</span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <h4 class="mt-2">Current usage</h4>
            <div class="grid-3">
                <?php foreach (array('leads' => array('Leads', 'max_leads'), 'properties' => array('Properties', 'max_properties'), 'agents' => array('Users', 'max_agents')) as $key => $meta): ?>
                    <?php $pct = $usagePercent($usage[$key], $currentPlan[$meta[1]]); ?>
                    <div class="stat-card">
                        <div class="stat-label"><?php echo e($meta[0]); ?></div>
                        <div class="stat-number" style="font-size:1.25rem;">
                            <?php echo number_format((int)$usage[$key]); ?> / <?php echo e($limitLabel($currentPlan[$meta[1]])); ?>
                        </div>
                        <div class="progress"><div class="progress-bar" style="width: <?php echo $pct; ?>%;<?php echo $pct >= 90 ? 'background:#e74c3c;' : ''; ?>"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!empty($latestSubscription)): ?>
            <div class="alert alert-warning">
                Your <strong><?php echo e($latestSubscription['plan_name']); ?></strong> subscription
                <?php echo $latestSubscription['status'] === 'active' ? 'expired' : 'is ' . e($latestSubscription['status']); ?>
                <?php if (!empty($latestSubscription['end_date'])): ?>
                    on <?php echo e(View::formatDate($latestSubscription['end_date'], 'F j, Y')); ?>
                <?php endif; ?>.
                Choose a plan below to continue adding leads, properties and users.
            </div>
        <?php else: ?>
            <div class="alert alert-info">You don't have an active subscription plan. Choose one below.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header"><h2>Available Plans</h2></div>
    <div class="card-body">
        <div class="grid-3">
            <?php foreach ($plans as $plan): ?>
                <?php
                $isCurrent = (int)$plan['id'] === $currentPlanId;
                if ($currentPrice === null) {
                    $action = 'subscribe';
                    $label = 'Choose ' . $plan['name'];
                } elseif ((float)$plan['price'] > $currentPrice) {
                    $action = 'upgrade';
                    $label = 'Upgrade to ' . $plan['name'];
                } else {
                    $action = 'downgrade';
                    $label = 'Switch to ' . $plan['name'];
                }
                $features = array_filter(array_map('trim', explode(',', (string)$plan['features'])), 'strlen');
                ?>
                <div class="card" style="<?php echo $isCurrent ? 'border:2px solid #27ae60;' : ''; ?>">
                    <div class="card-header d-flex justify-between align-center">
                        <h3><?php echo e($plan['name']); ?></h3>
                        <?php if ($isCurrent): ?><span class="badge badge-success">Current</span><?php endif; ?>
                    </div>
                    <div class="card-body">
                        <p class="stat-number" style="font-size:1.75rem;">
                            <?php echo e(View::formatCurrency($plan['price'])); ?>
                            <span class="small text-muted">/ <?php echo e($plan['billing_cycle'] === 'yearly' ? 'year' : 'month'); ?></span>
                        </p>
                        <p class="text-muted"><?php echo e($plan['description'] ?? ''); ?></p>
                        <ul>
                            <li><?php echo e($limitLabel($plan['max_leads'])); ?> leads</li>
                            <li><?php echo e($limitLabel($plan['max_properties'])); ?> properties</li>
                            <li><?php echo e($limitLabel($plan['max_agents'])); ?> users</li>
                            <?php foreach ($features as $feature): ?>
                                <li><?php echo e($feature); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="card-footer">
                        <?php if ($isCurrent): ?>
                            <button type="button" class="btn btn-secondary btn-block" disabled>Current plan</button>
                        <?php elseif ($canManage): ?>
                            <button type="button" class="btn <?php echo $action === 'downgrade' ? 'btn-outline' : 'btn-primary'; ?> btn-block js-change-plan"
                                    data-plan-id="<?php echo (int)$plan['id']; ?>"
                                    data-plan-name="<?php echo e($plan['name']); ?>"
                                    data-action="<?php echo e($action); ?>">
                                <?php echo e($label); ?>
                            </button>
                        <?php else: ?>
                            <span class="small text-muted">Ask an administrator to change the plan.</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($canManage && $subscription && $subscription['status'] === 'active'): ?>
    <div class="card mt-2">
        <div class="card-header"><h2>Billing Management</h2></div>
        <div class="card-body d-flex gap-2 flex-wrap">
            <a href="<?php echo e(url('subscription/payment-methods')); ?>" class="btn btn-outline">Payment Methods</a>
            <?php if (!empty($subscription['auto_renew'])): ?>
                <button type="button" class="btn btn-danger" id="js-cancel-subscription">Cancel Subscription</button>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div id="plan-message" class="alert" style="display:none;margin-top:1rem;"></div>

<script>
(function () {
    var demoMode = <?php echo $demoMode ? 'true' : 'false'; ?>;
    var messageBox = document.getElementById('plan-message');

    function showMessage(type, text) {
        messageBox.className = 'alert alert-' + type;
        messageBox.textContent = text;
        messageBox.style.display = 'block';
        messageBox.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    function post(path, body) {
        return fetch(BASE_URL + '/' + path, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().catch(function () {
                return {success: false, error: 'Unexpected server response (HTTP ' + response.status + ')'};
            });
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.js-change-plan'), function (button) {
        button.addEventListener('click', function () {
            var name = button.getAttribute('data-plan-name');
            var action = button.getAttribute('data-action');
            var question = action === 'upgrade'
                ? 'Upgrade to ' + name + '? The price difference is charged pro rata.'
                : (action === 'downgrade' ? 'Switch to ' + name + '?' : 'Subscribe to ' + name + '?');
            if (demoMode) {
                question += '\n\n(Demo mode: no payment will be taken.)';
            }
            if (!window.confirm(question)) {
                return;
            }
            var original = button.textContent;
            button.disabled = true;
            button.textContent = 'Processing…';
            post('subscription/change-plan', {plan_id: parseInt(button.getAttribute('data-plan-id'), 10)})
                .then(function (data) {
                    if (data.success && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    if (data.success) {
                        showMessage('success', data.message || 'Plan changed.');
                        setTimeout(function () { window.location.reload(); }, 1200);
                        return;
                    }
                    showMessage('error', data.error || data.message || 'Failed to change plan');
                    button.disabled = false;
                    button.textContent = original;
                })
                .catch(function (error) {
                    showMessage('error', 'Network error: ' + error.message);
                    button.disabled = false;
                    button.textContent = original;
                });
        });
    });

    var cancelButton = document.getElementById('js-cancel-subscription');
    if (cancelButton) {
        cancelButton.addEventListener('click', function () {
            if (!window.confirm('Cancel your subscription? It will not renew, and access continues until the end of the current billing period.')) {
                return;
            }
            cancelButton.disabled = true;
            post('subscription/cancel').then(function (data) {
                if (data.success) {
                    showMessage('success', data.message);
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    showMessage('error', data.error || data.message || 'Failed to cancel subscription');
                    cancelButton.disabled = false;
                }
            }).catch(function (error) {
                showMessage('error', 'Network error: ' + error.message);
                cancelButton.disabled = false;
            });
        });
    }
})();
</script>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>

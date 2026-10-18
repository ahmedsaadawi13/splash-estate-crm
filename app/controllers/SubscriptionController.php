<?php
// FILE: /app/controllers/SubscriptionController.php

/**
 * SplashEstate CRM - Subscription Controller
 * Plans, billing and the Stripe webhook endpoint.
 *
 * Modes:
 *  - Stripe mode (STRIPE_SECRET_KEY set): new subscriptions go through Stripe
 *    Checkout, plan changes/cancellations call the Stripe API, and the local
 *    subscription is kept in sync by the /subscription/webhook endpoint.
 *  - Demo mode (no key): plan changes are recorded locally only, no payment
 *    is taken; the UI labels this clearly.
 */

class SubscriptionController extends Controller {

    /** @var StripeService|null */
    private $stripe = null;
    private $subscriptionModel;

    public function __construct() {
        $this->subscriptionModel = $this->model('Subscription');

        require_once APP_PATH . '/helpers/StripeService.php';
        if (StripeService::isConfigured()) {
            try {
                $this->stripe = new StripeService();
            } catch (Exception $e) {
                error_log('Stripe initialization error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Current subscription, usage and available plans
     */
    public function index() {
        $tenantId = $this->requireBillingAccess('subscription.view');

        $subscription = $this->subscriptionModel->getActiveSubscription($tenantId);
        $currentPlan = $subscription ? $this->subscriptionModel->getPlan($subscription['plan_id']) : null;
        $latest = $subscription ? null : $this->subscriptionModel->getLatestSubscription($tenantId);

        $usageStats = $this->subscriptionModel->getUsageStats($tenantId);

        $this->view('subscription/index', array(
            'title' => 'Subscription & Billing',
            'subscription' => $subscription ?: null,
            'latestSubscription' => $latest ?: null,
            'currentPlan' => $currentPlan ?: null,
            'tenant' => $this->subscriptionModel->getTenant($tenantId),
            'plans' => $this->subscriptionModel->getActivePlans(),
            'usage' => array(
                'leads' => $usageStats['leads_count'],
                'properties' => $usageStats['properties_count'],
                'agents' => $usageStats['agents_count']
            ),
            'demoMode' => $this->stripe === null,
            'canManage' => can('subscription.manage'),
            'checkoutStatus' => isset($_GET['checkout']) && in_array($_GET['checkout'], array('success', 'cancelled'), true) ? $_GET['checkout'] : null
        ));
    }

    /**
     * Change plan (POST JSON {plan_id} or form field plan_id, CSRF required)
     */
    public function changePlan() {
        $tenantId = $this->requireBillingAccess('subscription.manage');
        $this->requirePostWithCsrf('subscription/index');

        $input = $this->readInput();
        $newPlanId = isset($input['plan_id']) ? (int)$input['plan_id'] : 0;
        $newPlan = $newPlanId > 0 ? $this->subscriptionModel->getPlan($newPlanId) : false;
        if (!$newPlan || $newPlan['status'] !== 'active') {
            $this->respond(false, 'Plan not found', 404);
        }

        $current = $this->subscriptionModel->getActiveSubscription($tenantId);
        if ($current && (int)$current['plan_id'] === (int)$newPlan['id']) {
            $this->respond(false, 'You are already on the ' . $newPlan['name'] . ' plan', 400);
        }

        // Demo mode: record the change locally, no payment is taken
        if ($this->stripe === null) {
            if (!$this->subscriptionModel->changePlan($tenantId, $newPlan['id'], 'active', null, 1)) {
                $this->respond(false, 'Failed to change plan', 500);
            }
            logActivity($this->getUserId(), $tenantId, 'changed_plan', 'subscription', (int)$newPlan['id']);
            $this->respond(true, 'Demo mode: switched to ' . $newPlan['name'] . ' locally. No payment was taken because Stripe is not configured.', 200, array('demo' => true));
        }

        $tenant = $this->subscriptionModel->getTenant($tenantId);

        try {
            // Free plan: end the paid Stripe subscription at period end
            if ((float)$newPlan['price'] <= 0) {
                $endDate = null;
                if (!empty($tenant['stripe_subscription_id'])) {
                    $stripeSub = $this->stripe->cancelSubscription($tenant['stripe_subscription_id'], false);
                    $endDate = !empty($stripeSub['current_period_end']) ? date('Y-m-d', (int)$stripeSub['current_period_end']) : null;
                }
                if ($endDate !== null) {
                    $this->subscriptionModel->scheduleCancellation($tenantId, $endDate);
                    $message = 'Your paid plan ends on ' . date('F j, Y', strtotime($endDate)) . '. Switch to ' . $newPlan['name'] . ' after that date.';
                } else {
                    $this->subscriptionModel->changePlan($tenantId, $newPlan['id'], 'active', null, 0);
                    $message = 'Switched to ' . $newPlan['name'];
                }
                logActivity($this->getUserId(), $tenantId, 'changed_plan', 'subscription', (int)$newPlan['id']);
                $this->respond(true, $message);
            }

            if (empty($newPlan['stripe_price_id'])) {
                $this->respond(false, 'This plan is not available for online purchase. Please contact support.', 400);
            }

            $customerId = $this->ensureStripeCustomer($tenant);

            if (empty($tenant['stripe_subscription_id'])) {
                // New subscription: send the user to Stripe Checkout (card data never touches our server)
                $session = $this->stripe->createCheckoutSession(array(
                    'customer' => $customerId,
                    'price_id' => $newPlan['stripe_price_id'],
                    'success_url' => url('subscription/index?checkout=success'),
                    'cancel_url' => url('subscription/index?checkout=cancelled'),
                    'client_reference_id' => $tenantId,
                    'metadata' => array('tenant_id' => (string)$tenantId, 'plan_id' => (string)$newPlan['id'])
                ));
                $this->respond(true, 'Redirecting to secure checkout…', 200, array('redirect' => $session['url']));
            }

            // Existing subscription: swap the price (prorated)
            $stripeSub = $this->stripe->getSubscription($tenant['stripe_subscription_id']);
            if (empty($stripeSub['items']['data'][0]['id'])) {
                throw new Exception('Stripe subscription has no items');
            }
            $updated = $this->stripe->updateSubscription($tenant['stripe_subscription_id'], array(
                'price_id' => $newPlan['stripe_price_id'],
                'subscription_item_id' => $stripeSub['items']['data'][0]['id'],
                'cancel_at_period_end' => false,
                'metadata' => array('tenant_id' => (string)$tenantId, 'plan_id' => (string)$newPlan['id'])
            ));

            $status = $this->mapStripeStatus(isset($updated['status']) ? $updated['status'] : 'active');
            $this->subscriptionModel->changePlan($tenantId, $newPlan['id'], $status ?: 'active', null, 1);
            logActivity($this->getUserId(), $tenantId, 'changed_plan', 'subscription', (int)$newPlan['id']);
            $this->respond(true, 'Subscription updated to ' . $newPlan['name']);
        } catch (Exception $e) {
            error_log('Stripe subscription error (tenant ' . $tenantId . '): ' . $e->getMessage());
            $this->respond(false, 'The payment provider rejected the request. Please try again or contact support.', 502);
        }
    }

    /**
     * Cancel at the end of the billing period (POST, CSRF required)
     */
    public function cancel() {
        $tenantId = $this->requireBillingAccess('subscription.manage');
        $this->requirePostWithCsrf('subscription/index');

        if (!$this->subscriptionModel->getActiveSubscription($tenantId)) {
            $this->respond(false, 'There is no active subscription to cancel', 400);
        }

        $tenant = $this->subscriptionModel->getTenant($tenantId);
        $endDate = null;

        try {
            if ($this->stripe !== null && !empty($tenant['stripe_subscription_id'])) {
                $stripeSub = $this->stripe->cancelSubscription($tenant['stripe_subscription_id'], false);
                if (!empty($stripeSub['current_period_end'])) {
                    $endDate = date('Y-m-d', (int)$stripeSub['current_period_end']);
                }
            }
        } catch (Exception $e) {
            error_log('Subscription cancellation error (tenant ' . $tenantId . '): ' . $e->getMessage());
            $this->respond(false, 'Failed to cancel the subscription with the payment provider. Please try again.', 502);
        }

        // Access continues until the end date; auto-renew is switched off
        $endDate = $this->subscriptionModel->scheduleCancellation($tenantId, $endDate);
        logActivity($this->getUserId(), $tenantId, 'cancelled', 'subscription', 0);

        $label = $endDate ? date('F j, Y', strtotime($endDate)) : 'the end of the billing period';
        $this->respond(true, ($this->stripe === null ? 'Demo mode: ' : '') . 'Your subscription will not renew. Access continues until ' . $label . '.', 200, array(
            'end_date' => $label,
            'demo' => $this->stripe === null
        ));
    }

    /**
     * Payment history (Stripe invoices in Stripe mode, local invoices otherwise)
     */
    public function invoices() {
        $tenantId = $this->requireBillingAccess('subscription.view');
        $tenant = $this->subscriptionModel->getTenant($tenantId);

        $invoices = array();
        $source = 'local';
        $loadError = false;

        if ($this->stripe !== null && !empty($tenant['stripe_customer_id'])) {
            try {
                $result = $this->stripe->listInvoices($tenant['stripe_customer_id'], 20);
                foreach (isset($result['data']) ? $result['data'] : array() as $invoice) {
                    $invoices[] = array(
                        'number' => !empty($invoice['number']) ? $invoice['number'] : $invoice['id'],
                        'date' => isset($invoice['created']) ? date('Y-m-d', (int)$invoice['created']) : null,
                        'description' => !empty($invoice['lines']['data'][0]['description']) ? $invoice['lines']['data'][0]['description'] : 'Subscription payment',
                        'amount' => (isset($invoice['amount_paid']) && $invoice['status'] === 'paid' ? $invoice['amount_paid'] : (isset($invoice['amount_due']) ? $invoice['amount_due'] : 0)) / 100,
                        'status' => isset($invoice['status']) ? $invoice['status'] : 'unknown',
                        'pdf_url' => self::httpsUrl(isset($invoice['invoice_pdf']) ? $invoice['invoice_pdf'] : ''),
                        'hosted_url' => self::httpsUrl(isset($invoice['hosted_invoice_url']) ? $invoice['hosted_invoice_url'] : '')
                    );
                }
                $source = 'stripe';
            } catch (Exception $e) {
                error_log('Fetch invoices error (tenant ' . $tenantId . '): ' . $e->getMessage());
                $loadError = true;
            }
        }

        if ($source === 'local') {
            foreach ($this->subscriptionModel->getLocalInvoices($tenantId) as $invoice) {
                $invoices[] = array(
                    'number' => $invoice['invoice_number'],
                    'date' => $invoice['issue_date'],
                    'description' => !empty($invoice['plan_name']) ? $invoice['plan_name'] . ' plan' : 'Subscription payment',
                    'amount' => (float)$invoice['total'],
                    'status' => $invoice['status'],
                    'pdf_url' => '',
                    'hosted_url' => ''
                );
            }
        }

        $this->view('subscription/invoices', array(
            'title' => 'Payment History',
            'invoices' => $invoices,
            'source' => $source,
            'loadError' => $loadError,
            'demoMode' => $this->stripe === null
        ));
    }

    /**
     * Manage payment methods in the Stripe Billing Portal
     */
    public function paymentMethods() {
        $tenantId = $this->requireBillingAccess('subscription.manage');

        if ($this->stripe === null) {
            $this->setFlash('info', 'Demo mode: payment methods are managed in Stripe once STRIPE_SECRET_KEY is configured.');
            $this->redirect('subscription/index');
        }

        $tenant = $this->subscriptionModel->getTenant($tenantId);
        try {
            $customerId = $this->ensureStripeCustomer($tenant);
            $session = $this->stripe->createBillingPortalSession($customerId, url('subscription/index'));
            header('Location: ' . $session['url']);
            exit;
        } catch (Exception $e) {
            error_log('Billing portal error (tenant ' . $tenantId . '): ' . $e->getMessage());
            $this->setFlash('error', 'The billing portal is not available right now. Please try again later.');
            $this->redirect('subscription/index');
        }
    }

    /**
     * Stripe webhook endpoint: POST /subscription/webhook
     * Public (no login/CSRF); authenticated by the Stripe-Signature header.
     */
    public function webhook() {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(array('error' => 'Method not allowed'), 405);
        }

        $secret = defined('STRIPE_WEBHOOK_SECRET') ? (string)STRIPE_WEBHOOK_SECRET : '';
        if ($secret === '') {
            $this->jsonResponse(array('error' => 'Webhook not configured'), 503);
        }

        $payload = (string)file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string)$_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

        if (!StripeService::verifyWebhookSignature($payload, $signature, $secret)) {
            $this->jsonResponse(array('error' => 'Invalid signature'), 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type']) || !isset($event['data']['object']) || !is_array($event['data']['object'])) {
            $this->jsonResponse(array('error' => 'Invalid payload'), 400);
        }

        // Idempotency: each event is processed once (Stripe retries deliveries)
        $isNew = $this->subscriptionModel->recordStripeEvent($event['id'], $event['type']);
        if ($isNew === false) {
            $this->jsonResponse(array('received' => true, 'duplicate' => true));
        }

        try {
            $tenantId = $this->handleStripeEvent($event['type'], $event['data']['object']);
            if ($isNew) {
                $this->subscriptionModel->tagStripeEvent($event['id'], $tenantId);
            }
        } catch (Exception $e) {
            error_log('Stripe webhook ' . $event['type'] . ' (' . $event['id'] . ') failed: ' . $e->getMessage());
            if ($isNew) {
                $this->subscriptionModel->forgetStripeEvent($event['id']);
            }
            $this->jsonResponse(array('error' => 'Processing failed'), 500);
        }

        $this->jsonResponse(array('received' => true));
    }

    /**
     * Apply a Stripe event to local state. Handlers are idempotent.
     * @return int|null Tenant the event applied to
     */
    private function handleStripeEvent($type, $object) {
        switch ($type) {
            case 'checkout.session.completed':
                return $this->handleCheckoutCompleted($object);

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                return $this->handleSubscriptionUpdate($object);

            case 'customer.subscription.deleted':
                return $this->handleSubscriptionDeleted($object);

            case 'invoice.paid':
            case 'invoice.payment_succeeded':
                return $this->handlePaymentSucceeded($object);

            case 'invoice.payment_failed':
                return $this->handlePaymentFailed($object);
        }
        return null; // other event types are acknowledged and ignored
    }

    /**
     * Checkout finished: link the Stripe customer/subscription to the tenant
     */
    private function handleCheckoutCompleted($session) {
        if (isset($session['mode']) && $session['mode'] !== 'subscription') {
            return null;
        }
        $tenant = $this->tenantForCustomer(isset($session['customer']) ? $session['customer'] : '');
        if (!$tenant && !empty($session['client_reference_id']) && ctype_digit((string)$session['client_reference_id'])) {
            // Our own checkout sessions carry the tenant id; only trust it for tenants not yet linked to another customer
            $candidate = $this->subscriptionModel->getTenant((int)$session['client_reference_id']);
            if ($candidate && empty($candidate['stripe_customer_id'])) {
                $tenant = $candidate;
            }
        }
        if (!$tenant) {
            error_log('Stripe checkout.session.completed: no tenant for customer');
            return null;
        }

        $this->subscriptionModel->updateTenantBilling($tenant['id'], array(
            'stripe_customer_id' => isset($session['customer']) ? (string)$session['customer'] : $tenant['stripe_customer_id'],
            'stripe_subscription_id' => isset($session['subscription']) ? (string)$session['subscription'] : $tenant['stripe_subscription_id']
        ));

        $planId = isset($session['metadata']['plan_id']) ? (int)$session['metadata']['plan_id'] : 0;
        if ($planId && $this->subscriptionModel->getPlan($planId)) {
            $this->subscriptionModel->changePlan($tenant['id'], $planId, 'active', null, 1);
        }
        return (int)$tenant['id'];
    }

    /**
     * Subscription created/updated in Stripe: sync plan, status and end date
     */
    private function handleSubscriptionUpdate($subscription) {
        $tenant = $this->tenantForCustomer(isset($subscription['customer']) ? $subscription['customer'] : '');
        if (!$tenant) {
            error_log('Stripe subscription event: no tenant for customer');
            return null;
        }

        $status = $this->mapStripeStatus(isset($subscription['status']) ? $subscription['status'] : '');
        if ($status === null) {
            return (int)$tenant['id']; // incomplete checkout etc. - nothing to apply yet
        }

        $plan = false;
        if (!empty($subscription['items']['data'][0]['price']['id'])) {
            $plan = $this->subscriptionModel->getPlanByStripePriceId($subscription['items']['data'][0]['price']['id']);
        }
        if (!$plan && !empty($subscription['metadata']['plan_id'])) {
            $plan = $this->subscriptionModel->getPlan((int)$subscription['metadata']['plan_id']);
        }
        if (!$plan) {
            error_log('Stripe subscription event: unknown price for tenant ' . $tenant['id']);
            return (int)$tenant['id'];
        }

        $this->subscriptionModel->updateTenantBilling($tenant['id'], array('stripe_subscription_id' => (string)$subscription['id']));

        $cancelAtPeriodEnd = !empty($subscription['cancel_at_period_end']);
        $endDate = ($cancelAtPeriodEnd && !empty($subscription['current_period_end'])) ? date('Y-m-d', (int)$subscription['current_period_end']) : null;

        $this->subscriptionModel->changePlan($tenant['id'], $plan['id'], $status, $endDate, $cancelAtPeriodEnd ? 0 : 1);
        return (int)$tenant['id'];
    }

    /**
     * Subscription ended in Stripe
     */
    private function handleSubscriptionDeleted($subscription) {
        $tenant = $this->tenantForCustomer(isset($subscription['customer']) ? $subscription['customer'] : '');
        if (!$tenant) {
            return null;
        }
        // Ignore events about an old subscription the tenant already replaced
        if (!empty($tenant['stripe_subscription_id']) && $tenant['stripe_subscription_id'] !== $subscription['id']) {
            return (int)$tenant['id'];
        }
        $this->subscriptionModel->cancelAllActive($tenant['id']);
        $this->subscriptionModel->updateTenantBilling($tenant['id'], array('stripe_subscription_id' => null));
        $this->notifyTenantAdmins($tenant['id'], 'Subscription ended', 'Your SplashEstate subscription has ended.', 'subscription/index', 'warning');
        return (int)$tenant['id'];
    }

    /**
     * Invoice paid: record invoice + payment locally
     */
    private function handlePaymentSucceeded($invoice) {
        $tenant = $this->tenantForCustomer(isset($invoice['customer']) ? $invoice['customer'] : '');
        if (!$tenant) {
            return null;
        }
        $this->subscriptionModel->recordStripeInvoicePaid($tenant['id'], $invoice);
        return (int)$tenant['id'];
    }

    /**
     * Invoice payment failed: tell the tenant admins
     */
    private function handlePaymentFailed($invoice) {
        $tenant = $this->tenantForCustomer(isset($invoice['customer']) ? $invoice['customer'] : '');
        if (!$tenant) {
            return null;
        }
        error_log('Stripe payment failed for tenant ' . $tenant['id']);
        $this->notifyTenantAdmins($tenant['id'], 'Payment failed', 'We could not collect your subscription payment. Please update your payment method.', 'subscription/index', 'warning');
        return (int)$tenant['id'];
    }

    /**
     * Map a Stripe subscription status to tenant_subscriptions.status
     * @return string|null null = not yet applicable (incomplete)
     */
    private function mapStripeStatus($status) {
        switch ($status) {
            case 'active':
            case 'trialing':
                return 'active';
            case 'past_due':
            case 'unpaid':
            case 'paused':
                return 'suspended';
            case 'canceled':
            case 'incomplete_expired':
                return 'cancelled';
        }
        return null;
    }

    /**
     * Find the tenant linked to a Stripe customer
     */
    private function tenantForCustomer($customerId) {
        return is_string($customerId) && $customerId !== '' ? $this->subscriptionModel->getTenantByStripeCustomer($customerId) : false;
    }

    /**
     * Get or create the Stripe customer of a tenant
     * @return string Customer ID
     */
    private function ensureStripeCustomer($tenant) {
        if (!empty($tenant['stripe_customer_id'])) {
            return $tenant['stripe_customer_id'];
        }
        $customer = $this->stripe->createCustomer(array(
            'email' => $tenant['email'],
            'name' => $tenant['name'],
            'metadata' => array('tenant_id' => (string)$tenant['id'])
        ));
        $this->subscriptionModel->updateTenantBilling($tenant['id'], array('stripe_customer_id' => $customer['id']));
        return $customer['id'];
    }

    /**
     * Notify every active tenant admin (in-app)
     */
    private function notifyTenantAdmins($tenantId, $title, $message, $link, $type) {
        try {
            $stmt = $this->subscriptionModel->getConnection()->prepare(
                "SELECT id FROM users WHERE tenant_id = :tenant_id AND role = 'tenant_admin' AND status = 'active'"
            );
            $stmt->execute(array(':tenant_id' => (int)$tenantId));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                createNotification($tenantId, $userId, $title, $message, $link, $type);
            }
        } catch (Exception $e) {
            error_log('notifyTenantAdmins failed: ' . $e->getMessage());
        }
    }

    /**
     * Permission + tenant gate
     * @return int Tenant ID
     */
    private function requireBillingAccess($permission) {
        $this->requirePermission($permission);
        $tenantId = $this->getTenantId();
        if (empty($tenantId)) {
            if ($this->wantsJson()) {
                $this->jsonResponse(array('success' => false, 'error' => 'Billing is managed per agency'), 400);
            }
            $this->setFlash('error', 'Billing is managed per agency. Sign in with an agency account.');
            $this->redirect('dashboard/index');
        }
        return (int)$tenantId;
    }

    /**
     * Read JSON body or form fields
     */
    private function readInput() {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (stripos($contentType, 'application/json') !== false) {
            $input = json_decode((string)file_get_contents('php://input'), true);
            return is_array($input) ? $input : array();
        }
        return $_POST;
    }

    /**
     * Respond with JSON (fetch) or flash + redirect (plain form post)
     */
    private function respond($success, $message, $code = 200, $extra = array()) {
        if ($this->wantsJson() || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)) {
            $body = array('success' => (bool)$success, 'message' => $message);
            if (!$success) {
                $body['error'] = $message;
            }
            $this->jsonResponse(array_merge($body, $extra), $code);
        }
        if (!empty($extra['redirect'])) {
            header('Location: ' . $extra['redirect']);
            exit;
        }
        $this->setFlash($success ? 'success' : 'error', $message);
        $this->redirect('subscription/index');
    }

    /**
     * Only allow https links from the payment provider in views
     */
    private static function httpsUrl($url) {
        return is_string($url) && stripos($url, 'https://') === 0 ? $url : '';
    }
}

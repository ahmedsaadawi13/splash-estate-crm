<?php
// FILE: /tests/cases/BillingTest.php
// Stripe signature verification and the Subscription model's billing state.

require_once APP_PATH . '/helpers/StripeService.php';
require_once APP_PATH . '/models/Subscription.php';

function test_stripe_header($payload, $secret, $timestamp) {
    return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
}

$suite->add('Stripe', 'verifyWebhookSignature() accepts valid signatures within 5 minutes', function () {
    $payload = '{"id":"evt_1","type":"invoice.paid"}';
    $now = 1760000000;
    $secret = 'whsec_test';
    assert_true(StripeService::verifyWebhookSignature($payload, test_stripe_header($payload, $secret, $now), $secret, 300, $now + 299));
    // Several v1 signatures (secret rotation): any match is accepted
    $header = 't=' . $now . ',v1=' . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', $now . '.' . $payload, $secret) . ',v0=ignored';
    assert_true(StripeService::verifyWebhookSignature($payload, $header, $secret, 300, $now));
});

$suite->add('Stripe', 'verifyWebhookSignature() rejects tampered, stale, malformed or unsigned input', function () {
    $payload = '{"id":"evt_1"}';
    $now = 1760000000;
    $secret = 'whsec_test';
    $header = test_stripe_header($payload, $secret, $now);
    assert_false(StripeService::verifyWebhookSignature($payload . 'x', $header, $secret, 300, $now), 'tampered');
    assert_false(StripeService::verifyWebhookSignature($payload, $header, 'whsec_wrong', 300, $now), 'wrong secret');
    assert_false(StripeService::verifyWebhookSignature($payload, $header, $secret, 300, $now + 301), 'replayed after tolerance');
    assert_false(StripeService::verifyWebhookSignature($payload, $header, $secret, 300, $now - 301), 'from the future');
    assert_false(StripeService::verifyWebhookSignature($payload, '', $secret, 300, $now), 'missing header');
    assert_false(StripeService::verifyWebhookSignature($payload, 'garbage', $secret, 300, $now), 'malformed');
    assert_false(StripeService::verifyWebhookSignature($payload, 't=abc,v1=' . hash_hmac('sha256', 'abc.' . $payload, $secret), $secret, 300, $now), 'non-numeric timestamp');
    assert_false(StripeService::verifyWebhookSignature($payload, $header, '', 300, $now), 'empty secret');
});

$suite->add('Stripe', 'service requires a key; demo mode when STRIPE_SECRET_KEY is empty', function () {
    if (defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '') {
        skip_test('STRIPE_SECRET_KEY is configured');
    }
    assert_false(StripeService::isConfigured());
    assert_throws('Exception', function () {
        new StripeService();
    });
});

$suite->add('Subscription', 'changePlan() is idempotent and keeps one active subscription', function () {
    $model = new Subscription();
    $pdo = Database::getInstance()->getConnection();
    $pdo->exec("UPDATE tenant_subscriptions SET end_date = NULL WHERE tenant_id = 2");

    assert_true($model->changePlan(2, 2));
    assert_true($model->changePlan(2, 2, 'active', '2030-01-31', 0), 'same plan again only updates');
    $active = $pdo->query("SELECT * FROM tenant_subscriptions WHERE tenant_id = 2 AND status = 'active'")->fetchAll();
    assert_equals(1, count($active));
    assert_equals(2, (int)$active[0]['plan_id']);
    assert_equals('2030-01-31', $active[0]['end_date']);
    assert_equals(0, (int)$active[0]['auto_renew']);

    assert_true($model->changePlan(2, 3));
    $current = $model->getActiveSubscription(2);
    assert_equals(3, (int)$current['plan_id']);
    assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM tenant_subscriptions WHERE tenant_id = 2 AND status = 'active'")->fetchColumn());
});

$suite->add('Subscription', 'scheduleCancellation() stops renewal but keeps access until the end date', function () {
    $model = new Subscription();
    $model->changePlan(2, 3, 'active', null, 1);
    $end = $model->scheduleCancellation(2, '2031-05-01');
    assert_equals('2031-05-01', $end);
    $current = $model->getActiveSubscription(2);
    assert_equals('active', $current['status']);
    assert_equals(0, (int)$current['auto_renew']);
});

$suite->add('Subscription', 'Stripe event ids and paid invoices are recorded once', function () {
    $model = new Subscription();
    $eventId = 'evt_' . bin2hex(random_bytes(6));
    assert_true($model->recordStripeEvent($eventId, 'invoice.paid'));
    assert_false($model->recordStripeEvent($eventId, 'invoice.paid'), 'duplicate detected');
    $model->forgetStripeEvent($eventId);
    assert_true($model->recordStripeEvent($eventId, 'invoice.paid'), 'can be re-processed after a failure');

    $invoice = array('id' => 'in_' . bin2hex(random_bytes(4)), 'number' => 'TEST-' . bin2hex(random_bytes(3)), 'amount_paid' => 4900, 'created' => time(), 'payment_intent' => 'pi_123');
    assert_true($model->recordStripeInvoicePaid(2, $invoice));
    assert_false($model->recordStripeInvoicePaid(2, $invoice), 'second delivery ignored');
    $rows = $model->getLocalInvoices(2);
    $numbers = array_column($rows, 'invoice_number');
    assert_contains($invoice['number'], $numbers);
    foreach ($rows as $row) {
        if ($row['invoice_number'] === $invoice['number']) {
            assert_equals('49.00', $row['total']);
            assert_equals('pi_123', $row['transaction_id']);
        }
    }
    assert_not_contains($invoice['number'], array_column($model->getLocalInvoices(1), 'invoice_number'), 'tenant scoped');
});

$suite->add('Subscription', 'tenant lookup by Stripe customer id', function () {
    $model = new Subscription();
    $model->updateTenantBilling(2, array('stripe_customer_id' => 'cus_test_2', 'name' => 'ignored'));
    $tenant = $model->getTenantByStripeCustomer('cus_test_2');
    assert_equals(2, (int)$tenant['id']);
    assert_equals('Metro Properties Inc', $tenant['name'], 'only billing columns are writable');
    assert_false($model->getTenantByStripeCustomer(''));
    assert_false($model->getTenantByStripeCustomer('cus_unknown'));
    $model->updateTenantBilling(2, array('stripe_customer_id' => null));
});

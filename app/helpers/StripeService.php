<?php
// FILE: /app/helpers/StripeService.php

/**
 * SplashEstate CRM - Stripe Service
 * Minimal dependency-free client for the Stripe REST API.
 *
 * Card data never touches this server: new subscriptions go through Stripe
 * Checkout and payment methods are managed in the Stripe Billing Portal.
 * Webhook signatures are verified with verifyWebhookSignature(), which does
 * not need an API key.
 */

class StripeService {

    const SIGNATURE_TOLERANCE = 300; // seconds

    private $apiKey;
    private $apiVersion = '2023-10-16';
    private $apiBase = 'https://api.stripe.com/v1';

    /**
     * @param string|null $apiKey Secret key (defaults to STRIPE_SECRET_KEY)
     * @throws Exception when no key is configured
     */
    public function __construct($apiKey = null) {
        $this->apiKey = $apiKey ?: (defined('STRIPE_SECRET_KEY') ? (string)STRIPE_SECRET_KEY : '');

        if ($this->apiKey === '') {
            throw new Exception('Stripe API key is required');
        }
    }

    /**
     * Whether a Stripe secret key is configured (otherwise billing runs in demo mode)
     * @return bool
     */
    public static function isConfigured() {
        return defined('STRIPE_SECRET_KEY') && is_string(STRIPE_SECRET_KEY) && STRIPE_SECRET_KEY !== '';
    }

    /**
     * Create a customer
     * @param array $data email, name, description, metadata
     * @return array Customer object
     */
    public function createCustomer($data) {
        return $this->request('POST', '/customers', array(
            'email' => isset($data['email']) ? $data['email'] : null,
            'name' => isset($data['name']) ? $data['name'] : null,
            'description' => isset($data['description']) ? $data['description'] : null,
            'metadata' => isset($data['metadata']) ? $data['metadata'] : array()
        ));
    }

    /**
     * @param string $customerId Stripe customer ID
     * @return array Customer object
     */
    public function getCustomer($customerId) {
        return $this->request('GET', '/customers/' . self::id($customerId));
    }

    /**
     * @param string $customerId Stripe customer ID
     * @param array $data Update data
     * @return array Customer object
     */
    public function updateCustomer($customerId, $data) {
        return $this->request('POST', '/customers/' . self::id($customerId), $data);
    }

    /**
     * Attach a payment method (created client-side with Stripe.js) to a customer
     */
    public function attachPaymentMethod($paymentMethodId, $customerId) {
        return $this->request('POST', '/payment_methods/' . self::id($paymentMethodId) . '/attach', array(
            'customer' => $customerId
        ));
    }

    /**
     * Set default payment method for customer invoices
     */
    public function setDefaultPaymentMethod($customerId, $paymentMethodId) {
        return $this->updateCustomer($customerId, array(
            'invoice_settings' => array(
                'default_payment_method' => $paymentMethodId
            )
        ));
    }

    /**
     * Create a Checkout Session for a new subscription
     * @param array $data customer, price_id, success_url, cancel_url, client_reference_id, metadata
     * @return array Session object (redirect the user to ['url'])
     */
    public function createCheckoutSession($data) {
        $metadata = isset($data['metadata']) ? $data['metadata'] : array();
        return $this->request('POST', '/checkout/sessions', array(
            'mode' => 'subscription',
            'customer' => $data['customer'],
            'line_items' => array(array('price' => $data['price_id'], 'quantity' => 1)),
            'success_url' => $data['success_url'],
            'cancel_url' => $data['cancel_url'],
            'client_reference_id' => isset($data['client_reference_id']) ? (string)$data['client_reference_id'] : null,
            'metadata' => $metadata,
            'subscription_data' => array('metadata' => $metadata)
        ));
    }

    /**
     * Create a Billing Portal session (manage cards, invoices, cancellation)
     * @param string $customerId Customer ID
     * @param string $returnUrl URL to return to
     * @return array Session object (redirect the user to ['url'])
     */
    public function createBillingPortalSession($customerId, $returnUrl) {
        return $this->request('POST', '/billing_portal/sessions', array(
            'customer' => $customerId,
            'return_url' => $returnUrl
        ));
    }

    /**
     * Create a subscription for a customer that already has a default payment method
     * @param array $data customer, price_id, trial_days, metadata
     * @return array Subscription object
     */
    public function createSubscription($data) {
        $params = array(
            'customer' => $data['customer'],
            'items' => array(
                array('price' => $data['price_id'])
            )
        );

        if (isset($data['trial_days'])) {
            $params['trial_period_days'] = (int)$data['trial_days'];
        }
        if (isset($data['metadata'])) {
            $params['metadata'] = $data['metadata'];
        }

        return $this->request('POST', '/subscriptions', $params);
    }

    /**
     * @param string $subscriptionId Subscription ID
     * @return array Subscription object
     */
    public function getSubscription($subscriptionId) {
        return $this->request('GET', '/subscriptions/' . self::id($subscriptionId));
    }

    /**
     * Update subscription (change price and/or cancel_at_period_end)
     * @param string $subscriptionId Subscription ID
     * @param array $data price_id + subscription_item_id, cancel_at_period_end, metadata
     * @return array Subscription object
     */
    public function updateSubscription($subscriptionId, $data) {
        $params = array();

        if (isset($data['price_id'])) {
            $params['items'] = array(
                array(
                    'id' => $data['subscription_item_id'],
                    'price' => $data['price_id']
                )
            );
            $params['proration_behavior'] = 'always_invoice';
        }
        if (isset($data['cancel_at_period_end'])) {
            $params['cancel_at_period_end'] = (bool)$data['cancel_at_period_end'];
        }
        if (isset($data['metadata'])) {
            $params['metadata'] = $data['metadata'];
        }

        return $this->request('POST', '/subscriptions/' . self::id($subscriptionId), $params);
    }

    /**
     * Cancel subscription
     * @param string $subscriptionId Subscription ID
     * @param bool $immediately Cancel now (true) or at the end of the period (false)
     * @return array Subscription object
     */
    public function cancelSubscription($subscriptionId, $immediately = false) {
        if ($immediately) {
            return $this->request('DELETE', '/subscriptions/' . self::id($subscriptionId));
        }
        return $this->updateSubscription($subscriptionId, array('cancel_at_period_end' => true));
    }

    /**
     * List invoices for customer
     * @param string $customerId Customer ID
     * @param int $limit Number of invoices
     * @return array List object
     */
    public function listInvoices($customerId, $limit = 10) {
        return $this->request('GET', '/invoices', array(
            'customer' => $customerId,
            'limit' => max(1, min(100, (int)$limit))
        ));
    }

    /**
     * @param string $invoiceId Invoice ID
     * @return array Invoice object
     */
    public function getInvoice($invoiceId) {
        return $this->request('GET', '/invoices/' . self::id($invoiceId));
    }

    /**
     * Verify a Stripe-Signature header ("t=<ts>,v1=<sig>[,v1=<sig>…]").
     * @param string $payload Raw request body
     * @param string $signatureHeader Stripe-Signature header
     * @param string $secret Endpoint signing secret (whsec_…)
     * @param int $tolerance Max age in seconds
     * @param int|null $now Current time (for tests)
     * @return bool
     */
    public static function verifyWebhookSignature($payload, $signatureHeader, $secret, $tolerance = self::SIGNATURE_TOLERANCE, $now = null) {
        if (!is_string($payload) || !is_string($signatureHeader) || $signatureHeader === '' || !is_string($secret) || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = array();
        foreach (explode(',', $signatureHeader) as $element) {
            $pair = explode('=', trim($element), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't' && ctype_digit($pair[1])) {
                $timestamp = (int)$pair[1];
            } elseif ($pair[0] === 'v1' && $pair[1] !== '') {
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || empty($signatures)) {
            return false;
        }

        $now = $now === null ? time() : (int)$now;
        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test API connection
     * @return array success, message
     */
    public function testConnection() {
        try {
            $this->request('GET', '/customers', array('limit' => 1));
            return array('success' => true, 'message' => 'Stripe API connection successful');
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Make an API request
     * @param string $method GET, POST or DELETE
     * @param string $endpoint API path
     * @param array $params Parameters
     * @return array Decoded response
     * @throws Exception on transport or API errors (message never contains the API key)
     */
    private function request($method, $endpoint, $params = array()) {
        if (!function_exists('curl_init')) {
            throw new Exception('The PHP curl extension is required for Stripe');
        }

        $url = $this->apiBase . $endpoint;
        $query = http_build_query(self::encodeParams($params), '', '&');

        $headers = array(
            'Authorization: Bearer ' . $this->apiKey,
            'Stripe-Version: ' . $this->apiVersion,
            'Content-Type: application/x-www-form-urlencoded'
        );

        $ch = curl_init();
        if ($method === 'GET') {
            if ($query !== '') {
                $url .= '?' . $query;
            }
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
            // Safe retries: Stripe de-duplicates POSTs with the same key
            $headers[] = 'Idempotency-Key: ' . bin2hex(random_bytes(16));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } else {
            curl_close($ch);
            throw new Exception('Unsupported HTTP method');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new Exception('Stripe API request failed: ' . $error);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Stripe API returned an invalid response (HTTP ' . $httpCode . ')');
        }

        if ($httpCode >= 400) {
            $message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            throw new Exception('Stripe API error: ' . $message);
        }

        return $data;
    }

    /**
     * Prepare parameters for form encoding: drop nulls, booleans as "true"/"false"
     */
    private static function encodeParams($params) {
        $out = array();
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $out[$key] = self::encodeParams($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Validate a Stripe object ID before putting it in a URL path
     */
    private static function id($id) {
        $id = (string)$id;
        if (!preg_match('/^[A-Za-z0-9_]{1,255}$/', $id)) {
            throw new Exception('Invalid Stripe object ID');
        }
        return $id;
    }
}

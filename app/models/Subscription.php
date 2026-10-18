<?php
// FILE: /app/models/Subscription.php

/**
 * SplashEstate CRM - Subscription Model
 * Tenant subscriptions (tenant_subscriptions), plus the plan/tenant/invoice
 * queries the billing flow needs. Plan and tenant rows are read directly here
 * so billing does not depend on the Plan/Tenant model APIs.
 */

class Subscription extends Model {

    protected $table = 'tenant_subscriptions';

    private static $statuses = array('active', 'cancelled', 'expired', 'suspended');

    /**
     * Create trial subscription for new tenant
     * @param int $tenantId Tenant ID
     * @return int|bool Subscription ID or false
     */
    public function createTrialSubscription($tenantId) {
        $stmt = $this->conn->prepare("SELECT id FROM plans WHERE slug = 'free-trial' LIMIT 1");
        $stmt->execute();
        $planId = $stmt->fetchColumn();
        if (!$planId) {
            return false;
        }

        return $this->create(array(
            'tenant_id' => (int)$tenantId,
            'plan_id' => (int)$planId,
            'status' => 'active',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+30 days')),
            'auto_renew' => 0
        ));
    }

    /**
     * Create subscription
     * @param array $data Subscription data
     * @return int|bool Subscription ID or false
     */
    public function create($data) {
        $allowed = array('tenant_id', 'plan_id', 'status', 'start_date', 'end_date', 'auto_renew');
        $row = array_intersect_key($data, array_flip($allowed));
        $row += array('status' => 'active', 'start_date' => date('Y-m-d'), 'auto_renew' => 1);
        return parent::create($row);
    }

    /**
     * Get active (and not yet ended) subscription for tenant with plan details
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function getActiveSubscription($tenantId) {
        $sql = "SELECT ts.*, p.name AS plan_name, p.slug AS plan_slug, p.price, p.billing_cycle,
                       p.max_leads, p.max_properties, p.max_agents
                FROM {$this->table} ts
                JOIN plans p ON ts.plan_id = p.id
                WHERE ts.tenant_id = :tenant_id
                AND ts.status = 'active'
                AND (ts.end_date IS NULL OR ts.end_date >= CURDATE())
                ORDER BY ts.id DESC
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Most recent subscription of any status (to show expired/cancelled plans)
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function getLatestSubscription($tenantId) {
        $sql = "SELECT ts.*, p.name AS plan_name
                FROM {$this->table} ts
                JOIN plans p ON ts.plan_id = p.id
                WHERE ts.tenant_id = :tenant_id
                ORDER BY ts.id DESC
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Update subscription by ID (unscoped - caller verifies the tenant)
     * @param int $id Subscription ID
     * @param array $data Updated data
     * @return bool Success status
     */
    public function updateSubscription($id, $data) {
        $allowed = array('plan_id', 'status', 'start_date', 'end_date', 'auto_renew');
        return $this->updateById($id, array_intersect_key($data, array_flip($allowed)));
    }

    /**
     * Cancel subscription (unscoped - caller verifies the tenant)
     * @param int $id Subscription ID
     * @return bool Success status
     */
    public function cancel($id) {
        return $this->updateById($id, array('status' => 'cancelled'));
    }

    /**
     * Switch a tenant to a plan. Idempotent: if the current active subscription
     * is already on that plan only its status/end date/renewal flag are updated,
     * otherwise the active subscription is closed and a new one is started.
     * @param int $tenantId Tenant ID
     * @param int $planId Plan ID
     * @param string $status Subscription status
     * @param string|null $endDate Y-m-d end date (null = open ended)
     * @param int $autoRenew Auto renew flag
     * @return bool
     */
    public function changePlan($tenantId, $planId, $status = 'active', $endDate = null, $autoRenew = 1) {
        if (!in_array($status, self::$statuses, true)) {
            $status = 'active';
        }
        $ownTransaction = !$this->conn->inTransaction();
        try {
            if ($ownTransaction) {
                $this->conn->beginTransaction();
            }

            $current = $this->getActiveSubscription($tenantId);
            if ($current && (int)$current['plan_id'] === (int)$planId) {
                $this->updateById($current['id'], array('status' => $status, 'end_date' => $endDate, 'auto_renew' => (int)$autoRenew));
            } else {
                $stmt = $this->conn->prepare(
                    "UPDATE {$this->table} SET status = 'cancelled', auto_renew = 0, end_date = COALESCE(end_date, CURDATE())
                     WHERE tenant_id = :tenant_id AND status = 'active'"
                );
                $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
                $stmt->execute();

                $this->create(array(
                    'tenant_id' => (int)$tenantId,
                    'plan_id' => (int)$planId,
                    'status' => $status,
                    'start_date' => date('Y-m-d'),
                    'end_date' => $endDate,
                    'auto_renew' => (int)$autoRenew
                ));
            }

            if ($ownTransaction) {
                $this->conn->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($ownTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Subscription changePlan failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Stop auto-renewal; the subscription stays active until $endDate
     * @param int $tenantId Tenant ID
     * @param string|null $endDate Y-m-d (null keeps the existing end date, or one month from today)
     * @return string|null Effective end date
     */
    public function scheduleCancellation($tenantId, $endDate = null) {
        $current = $this->getActiveSubscription($tenantId);
        if (!$current) {
            return null;
        }
        if ($endDate === null) {
            $endDate = !empty($current['end_date']) ? $current['end_date'] : date('Y-m-d', strtotime('+1 month'));
        }
        $this->updateById($current['id'], array('auto_renew' => 0, 'end_date' => $endDate));
        return $endDate;
    }

    /**
     * Mark every active subscription of a tenant as cancelled (immediately)
     * @param int $tenantId Tenant ID
     * @return bool
     */
    public function cancelAllActive($tenantId) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET status = 'cancelled', auto_renew = 0, end_date = COALESCE(end_date, CURDATE())
             WHERE tenant_id = :tenant_id AND status = 'active'"
        );
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Get subscription history for tenant
     * @param int $tenantId Tenant ID
     * @return array Subscriptions
     */
    public function getHistory($tenantId) {
        $sql = "SELECT ts.*, p.name AS plan_name, p.price
                FROM {$this->table} ts
                JOIN plans p ON ts.plan_id = p.id
                WHERE ts.tenant_id = :tenant_id
                ORDER BY ts.id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Check if resource quota exceeded
     * @param int $tenantId Tenant ID
     * @param string $resource Resource type
     * @return bool True if within quota
     */
    public function checkQuota($tenantId, $resource) {
        return checkQuota($tenantId, $resource);
    }

    /**
     * Get usage statistics
     * @param int $tenantId Tenant ID
     * @return array leads_count, properties_count, agents_count
     */
    public function getUsageStats($tenantId) {
        $sql = "SELECT
                    (SELECT COUNT(*) FROM leads WHERE tenant_id = :t1) AS leads_count,
                    (SELECT COUNT(*) FROM properties WHERE tenant_id = :t2) AS properties_count,
                    (SELECT COUNT(*) FROM users WHERE tenant_id = :t3) AS agents_count";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':t1', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':t2', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':t3', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return array(
            'leads_count' => (int)$row['leads_count'],
            'properties_count' => (int)$row['properties_count'],
            'agents_count' => (int)$row['agents_count']
        );
    }

    // ------------------------------------------------------------------
    // Plans
    // ------------------------------------------------------------------

    /**
     * @param int $planId Plan ID
     * @return array|false
     */
    public function getPlan($planId) {
        $stmt = $this->conn->prepare("SELECT * FROM plans WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', (int)$planId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * @return array Active plans, cheapest first
     */
    public function getActivePlans() {
        $stmt = $this->conn->prepare("SELECT * FROM plans WHERE status = 'active' ORDER BY price ASC, id ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @param string $priceId Stripe price ID
     * @return array|false
     */
    public function getPlanByStripePriceId($priceId) {
        $stmt = $this->conn->prepare("SELECT * FROM plans WHERE stripe_price_id = :price LIMIT 1");
        $stmt->bindValue(':price', (string)$priceId);
        $stmt->execute();
        return $stmt->fetch();
    }

    // ------------------------------------------------------------------
    // Tenant billing fields
    // ------------------------------------------------------------------

    /**
     * @param int $tenantId Tenant ID
     * @return array|false Tenant row
     */
    public function getTenant($tenantId) {
        $stmt = $this->conn->prepare("SELECT * FROM tenants WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * @param string $customerId Stripe customer ID
     * @return array|false Tenant row
     */
    public function getTenantByStripeCustomer($customerId) {
        if (!is_string($customerId) || $customerId === '') {
            return false;
        }
        $stmt = $this->conn->prepare("SELECT * FROM tenants WHERE stripe_customer_id = :customer LIMIT 1");
        $stmt->bindValue(':customer', $customerId);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Store Stripe customer/subscription IDs on the tenant
     * @param int $tenantId Tenant ID
     * @param array $data stripe_customer_id and/or stripe_subscription_id
     * @return bool
     */
    public function updateTenantBilling($tenantId, $data) {
        $row = array_intersect_key($data, array_flip(array('stripe_customer_id', 'stripe_subscription_id')));
        if (empty($row)) {
            return true;
        }
        $sets = array();
        foreach (array_keys($row) as $field) {
            $sets[] = "`{$field}` = :{$field}";
        }
        $stmt = $this->conn->prepare("UPDATE tenants SET " . implode(', ', $sets) . " WHERE id = :id");
        foreach ($row as $field => $value) {
            $stmt->bindValue(':' . $field, $value === '' ? null : $value);
        }
        $stmt->bindValue(':id', (int)$tenantId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // ------------------------------------------------------------------
    // Invoices / payments
    // ------------------------------------------------------------------

    /**
     * Locally recorded invoices of a tenant (with payment info)
     * @param int $tenantId Tenant ID
     * @param int $limit Max rows
     * @return array
     */
    public function getLocalInvoices($tenantId, $limit = 50) {
        $sql = "SELECT i.*, p.name AS plan_name,
                       (SELECT pay.transaction_id FROM payments pay WHERE pay.invoice_id = i.id ORDER BY pay.id DESC LIMIT 1) AS transaction_id
                FROM invoices i
                LEFT JOIN tenant_subscriptions ts ON i.subscription_id = ts.id
                LEFT JOIN plans p ON ts.plan_id = p.id
                WHERE i.tenant_id = :tenant_id
                ORDER BY i.issue_date DESC, i.id DESC
                LIMIT :limit";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Record a paid Stripe invoice and its payment (idempotent on the invoice number)
     * @param int $tenantId Tenant ID
     * @param array $invoice Stripe invoice object
     * @return bool True if a new invoice was recorded
     */
    public function recordStripeInvoicePaid($tenantId, $invoice) {
        $number = !empty($invoice['number']) ? (string)$invoice['number'] : (string)$invoice['id'];
        $number = substr($number, 0, 50);

        $stmt = $this->conn->prepare("SELECT id FROM invoices WHERE invoice_number = :number LIMIT 1");
        $stmt->bindValue(':number', $number);
        $stmt->execute();
        if ($stmt->fetchColumn()) {
            return false;
        }

        $total = isset($invoice['amount_paid']) ? $invoice['amount_paid'] / 100 : 0;
        $tax = isset($invoice['tax']) && $invoice['tax'] !== null ? $invoice['tax'] / 100 : 0;
        $created = isset($invoice['created']) ? (int)$invoice['created'] : time();
        $active = $this->getActiveSubscription($tenantId);

        $ownTransaction = !$this->conn->inTransaction();
        try {
            if ($ownTransaction) {
                $this->conn->beginTransaction();
            }
            $stmt = $this->conn->prepare(
                "INSERT INTO invoices (tenant_id, subscription_id, invoice_number, amount, tax, total, status, issue_date, due_date, paid_date)
                 VALUES (:tenant_id, :subscription_id, :number, :amount, :tax, :total, 'paid', :issue_date, :due_date, CURDATE())"
            );
            $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':subscription_id', $active ? (int)$active['id'] : null, $active ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':number', $number);
            $stmt->bindValue(':amount', round($total - $tax, 2));
            $stmt->bindValue(':tax', round($tax, 2));
            $stmt->bindValue(':total', round($total, 2));
            $stmt->bindValue(':issue_date', date('Y-m-d', $created));
            $stmt->bindValue(':due_date', date('Y-m-d', !empty($invoice['due_date']) ? (int)$invoice['due_date'] : $created));
            $stmt->execute();
            $invoiceId = (int)$this->conn->lastInsertId();

            $transactionId = '';
            if (!empty($invoice['payment_intent'])) {
                $transactionId = is_array($invoice['payment_intent']) ? $invoice['payment_intent']['id'] : $invoice['payment_intent'];
            } elseif (!empty($invoice['charge'])) {
                $transactionId = is_array($invoice['charge']) ? $invoice['charge']['id'] : $invoice['charge'];
            }

            $stmt = $this->conn->prepare(
                "INSERT INTO payments (tenant_id, invoice_id, amount, payment_method, transaction_id, status, payment_date, notes)
                 VALUES (:tenant_id, :invoice_id, :amount, 'stripe', :transaction_id, 'completed', NOW(), :notes)"
            );
            $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_INT);
            $stmt->bindValue(':amount', round($total, 2));
            $stmt->bindValue(':transaction_id', substr((string)$transactionId, 0, 100));
            $stmt->bindValue(':notes', 'Stripe invoice ' . substr((string)$invoice['id'], 0, 100));
            $stmt->execute();

            if ($ownTransaction) {
                $this->conn->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($ownTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Stripe webhook idempotency (table stripe_events, see database/migrations)
    // ------------------------------------------------------------------

    /**
     * Remember a Stripe event ID.
     * @param string $eventId Stripe event ID
     * @param string $type Event type
     * @return bool|null true = first time seen, false = duplicate, null = tracking table missing
     */
    public function recordStripeEvent($eventId, $type) {
        try {
            $stmt = $this->conn->prepare("INSERT IGNORE INTO stripe_events (event_id, type) VALUES (:event_id, :type)");
            $stmt->bindValue(':event_id', substr((string)$eventId, 0, 100));
            $stmt->bindValue(':type', substr((string)$type, 0, 100));
            $stmt->execute();
            return $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                error_log('stripe_events table missing - run php migrate.php migrate (Stripe events are not de-duplicated)');
                return null;
            }
            throw $e;
        }
    }

    /**
     * Forget a Stripe event (after a processing failure, so Stripe's retry is handled)
     * @param string $eventId Stripe event ID
     */
    public function forgetStripeEvent($eventId) {
        try {
            $stmt = $this->conn->prepare("DELETE FROM stripe_events WHERE event_id = :event_id");
            $stmt->bindValue(':event_id', (string)$eventId);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log('forgetStripeEvent failed: ' . $e->getMessage());
        }
    }

    /**
     * Mark a recorded Stripe event with the tenant it applied to
     */
    public function tagStripeEvent($eventId, $tenantId) {
        try {
            $stmt = $this->conn->prepare("UPDATE stripe_events SET tenant_id = :tenant_id WHERE event_id = :event_id");
            $stmt->bindValue(':tenant_id', $tenantId ? (int)$tenantId : null, $tenantId ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':event_id', (string)$eventId);
            $stmt->execute();
        } catch (PDOException $e) {
            // optional bookkeeping only
        }
    }
}

<?php
// FILE: /app/models/Plan.php

/**
 * SplashEstate CRM - Plan Model
 * Handles subscription plans
 */

class Plan extends Model {

    protected $table = 'plans';

    /**
     * Get all active plans
     * @return array Plans
     */
    public function getActivePlans() {
        $sql = "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY price ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get plan by ID.
     * (Named findPlan because Model::getById($id, $tenantId) has a
     * different, tenant-scoped signature; plans are global.)
     * @param int $id Plan ID
     * @return array|false Plan data or false
     */
    public function findPlan($id) {
        return $this->findById($id);
    }

    /**
     * Get plan by slug
     * @param string $slug Plan slug
     * @return mixed Plan data or false
     */
    public function getBySlug($slug) {
        $sql = "SELECT * FROM {$this->table} WHERE slug = :slug LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':slug', $slug);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Create plan
     * @param array $data Plan data
     * @return int|bool Plan ID or false
     */
    public function createPlan($data) {
        $sql = "INSERT INTO {$this->table} (name, slug, description, price, billing_cycle, max_leads, max_properties, max_agents, features, status, created_at)
                VALUES (:name, :slug, :description, :price, :billing_cycle, :max_leads, :max_properties, :max_agents, :features, :status, NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':slug', $data['slug']);
        $stmt->bindValue(':description', isset($data['description']) ? $data['description'] : null);
        $stmt->bindValue(':price', $data['price']);
        $stmt->bindValue(':billing_cycle', isset($data['billing_cycle']) ? $data['billing_cycle'] : 'monthly');
        $stmt->bindValue(':max_leads', isset($data['max_leads']) ? $data['max_leads'] : -1, PDO::PARAM_INT);
        $stmt->bindValue(':max_properties', isset($data['max_properties']) ? $data['max_properties'] : -1, PDO::PARAM_INT);
        $stmt->bindValue(':max_agents', isset($data['max_agents']) ? $data['max_agents'] : -1, PDO::PARAM_INT);
        $stmt->bindValue(':features', isset($data['features']) ? $data['features'] : null);
        $stmt->bindValue(':status', isset($data['status']) ? $data['status'] : 'active');

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    /**
     * Update plan
     * @param int $id Plan ID
     * @param array $data Updated data
     * @return bool Success status
     */
    public function updatePlan($id, $data) {
        unset($data['id'], $data['updated_at']);
        if (empty($data)) {
            return true;
        }
        self::assertValidIdentifiers(array_keys($data));
        $fields = array();
        foreach (array_keys($data) as $field) {
            $fields[] = "`{$field}` = :{$field}";
        }
        $fieldList = implode(', ', $fields);

        $sql = "UPDATE {$this->table} SET {$fieldList}, updated_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Get plan by Stripe price ID
     * @param string $stripePriceId Stripe price ID
     * @return mixed Plan data or false
     */
    public function getByStripePriceId($stripePriceId) {
        $sql = "SELECT * FROM {$this->table} WHERE stripe_price_id = :stripe_price_id LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':stripe_price_id', $stripePriceId);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Get plan features as array
     * @param array $plan Plan data
     * @return array Features list
     */
    public function getFeaturesList($plan) {
        if (empty($plan['features'])) {
            return array();
        }
        return array_map('trim', explode(',', $plan['features']));
    }

    /**
     * Format plan limits for display
     * @param array $plan Plan data
     * @return array Formatted limits
     */
    public function getFormattedLimits($plan) {
        return array(
            'leads' => $plan['max_leads'] == -1 ? 'Unlimited' : number_format($plan['max_leads']),
            'properties' => $plan['max_properties'] == -1 ? 'Unlimited' : number_format($plan['max_properties']),
            'agents' => $plan['max_agents'] == -1 ? 'Unlimited' : number_format($plan['max_agents'])
        );
    }

    /**
     * Check if plan allows feature
     * @param array $plan Plan data
     * @param int $current Current count
     * @param string $type Resource type (leads, properties, agents)
     * @return bool Allowed
     */
    public function isWithinLimit($plan, $current, $type) {
        $limitField = 'max_' . $type;
        if (!isset($plan[$limitField])) {
            return false;
        }
        // -1 means unlimited
        if ($plan[$limitField] == -1) {
            return true;
        }
        return $current < $plan[$limitField];
    }
}

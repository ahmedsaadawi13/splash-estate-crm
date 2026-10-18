<?php
// FILE: /database/migrations/2026_10_18_000002_create_stripe_events_table.php

/**
 * Migration: CreateStripeEventsTable
 * Processed Stripe webhook event IDs, so retried deliveries are applied once
 * (see SubscriptionController::webhook()).
 */

class CreateStripeEventsTable extends Migration {

    public function up() {
        return $this->createTable('stripe_events', "
            `event_id` varchar(100) NOT NULL,
            `type` varchar(100) NOT NULL,
            `tenant_id` int(11) DEFAULT NULL,
            `processed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`event_id`),
            KEY `idx_tenant` (`tenant_id`),
            KEY `idx_processed` (`processed_at`)
        ");
    }

    public function down() {
        return $this->dropTable('stripe_events');
    }
}

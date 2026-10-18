<?php
// FILE: /database/migrations/2026_10_01_000000_baseline_phase_two_to_five_schema.php

/**
 * Migration: BaselinePhaseTwoToFiveSchema
 *
 * Baseline for installs created before the migration system existed. Brings a
 * database created from an older database.sql (Phase 1 schema or later) up to
 * the Phase 5 schema of September 2026:
 *   - Stripe columns on tenants/plans (Phase 3)
 *   - saved_searches, property_images (Phase 2)
 *   - webhooks, webhook_logs (Phase 3)
 *   - permissions, roles, role_permissions, user_roles + default RBAC data (Phase 5)
 *   - workflows, workflow_actions, workflow_executions, workflow_action_logs (Phase 5)
 *
 * Every step is idempotent, so on a database imported from the current
 * database.sql this migration changes nothing and is only recorded.
 * down() is intentionally a no-op: dropping these tables would destroy
 * production data. Restore from a backup to undo a baseline.
 */

class BaselinePhaseTwoToFiveSchema extends Migration {

    public function up() {
        // Phase 3: Stripe billing columns
        $this->addColumn('tenants', 'stripe_customer_id', "varchar(255) DEFAULT NULL COMMENT 'Stripe customer ID' AFTER `api_key`");
        $this->addColumn('tenants', 'stripe_subscription_id', "varchar(255) DEFAULT NULL COMMENT 'Stripe subscription ID' AFTER `stripe_customer_id`");
        $this->addIndex('tenants', 'idx_stripe_customer', 'stripe_customer_id');
        $this->addColumn('plans', 'stripe_price_id', "varchar(255) DEFAULT NULL COMMENT 'Stripe Price ID for billing' AFTER `billing_cycle`");
        $this->addIndex('plans', 'idx_stripe_price', 'stripe_price_id');

        // Tables, in foreign-key order
        foreach ($this->tableDefinitions() as $table => $definition) {
            $this->createTable($table, $definition);
        }

        $this->seedRbac();
        return true;
    }

    public function down() {
        // Irreversible by design (see class comment)
        return true;
    }

    /**
     * Default permissions, system roles and their permissions (only what is missing)
     */
    private function seedRbac() {
        $permissions = array(
            array('View Leads', 'leads.view', 'View leads list and details', 'leads'),
            array('Create Leads', 'leads.create', 'Create new leads', 'leads'),
            array('Edit Leads', 'leads.edit', 'Edit existing leads', 'leads'),
            array('Delete Leads', 'leads.delete', 'Delete leads', 'leads'),
            array('Export Leads', 'leads.export', 'Export leads data', 'leads'),
            array('View Clients', 'clients.view', 'View clients list and details', 'clients'),
            array('Create Clients', 'clients.create', 'Create new clients', 'clients'),
            array('Edit Clients', 'clients.edit', 'Edit existing clients', 'clients'),
            array('Delete Clients', 'clients.delete', 'Delete clients', 'clients'),
            array('View Properties', 'properties.view', 'View properties list and details', 'properties'),
            array('Create Properties', 'properties.create', 'Create new properties', 'properties'),
            array('Edit Properties', 'properties.edit', 'Edit existing properties', 'properties'),
            array('Delete Properties', 'properties.delete', 'Delete properties', 'properties'),
            array('Manage Property Images', 'properties.images', 'Upload and manage property images', 'properties'),
            array('View Deals', 'deals.view', 'View deals list and details', 'deals'),
            array('Create Deals', 'deals.create', 'Create new deals', 'deals'),
            array('Edit Deals', 'deals.edit', 'Edit existing deals', 'deals'),
            array('Delete Deals', 'deals.delete', 'Delete deals', 'deals'),
            array('View Tasks', 'tasks.view', 'View tasks list and details', 'tasks'),
            array('Create Tasks', 'tasks.create', 'Create new tasks', 'tasks'),
            array('Edit Tasks', 'tasks.edit', 'Edit existing tasks', 'tasks'),
            array('Delete Tasks', 'tasks.delete', 'Delete tasks', 'tasks'),
            array('View Reports', 'reports.view', 'Access reports and analytics', 'reports'),
            array('Export Reports', 'reports.export', 'Export report data', 'reports'),
            array('Manage Settings', 'settings.manage', 'Manage system settings', 'settings'),
            array('Manage Users', 'users.manage', 'Manage users and agents', 'settings'),
            array('Manage Roles', 'roles.manage', 'Manage roles and permissions', 'settings'),
            array('Manage Webhooks', 'webhooks.manage', 'Configure webhooks', 'settings'),
            array('View Subscription', 'subscription.view', 'View subscription details', 'settings'),
            array('Manage Subscription', 'subscription.manage', 'Upgrade/downgrade subscription', 'settings')
        );
        foreach ($permissions as $p) {
            $this->execute(
                "INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `module`) VALUES (:name, :slug, :description, :module)",
                array(':name' => $p[0], ':slug' => $p[1], ':description' => $p[2], ':module' => $p[3])
            );
        }

        $roles = array(
            'tenant_admin' => array('Tenant Admin', 'Full access to tenant resources'),
            'agent' => array('Agent', 'Standard agent access'),
            'sales_manager' => array('Sales Manager', 'Manage sales team and deals'),
            'viewer' => array('Viewer', 'Read-only access')
        );
        $created = array();
        foreach ($roles as $slug => $role) {
            $exists = $this->fetchValue("SELECT id FROM `roles` WHERE slug = :slug AND is_system = 1 AND tenant_id IS NULL LIMIT 1", array(':slug' => $slug));
            if (!$exists) {
                $this->execute(
                    "INSERT INTO `roles` (`tenant_id`, `name`, `slug`, `description`, `is_system`) VALUES (NULL, :name, :slug, :description, 1)",
                    array(':name' => $role[0], ':slug' => $slug, ':description' => $role[1])
                );
                $created[] = $slug;
            }
        }

        // Only grant default permissions to roles created here, never alter customised roles
        $grants = array(
            'tenant_admin' => null, // every permission
            'agent' => array('leads.view', 'leads.create', 'leads.edit', 'clients.view', 'clients.create', 'clients.edit',
                'properties.view', 'deals.view', 'deals.create', 'deals.edit', 'tasks.view', 'tasks.create', 'tasks.edit',
                'tasks.delete', 'reports.view'),
            'sales_manager' => array('leads.view', 'leads.create', 'leads.edit', 'leads.delete', 'leads.export',
                'clients.view', 'clients.create', 'clients.edit', 'properties.view', 'deals.view', 'deals.create',
                'deals.edit', 'deals.delete', 'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete',
                'reports.view', 'reports.export', 'users.manage'),
            'viewer' => array('leads.view', 'clients.view', 'properties.view', 'deals.view', 'tasks.view', 'reports.view')
        );
        foreach ($created as $slug) {
            $roleId = (int)$this->fetchValue("SELECT id FROM `roles` WHERE slug = :slug AND is_system = 1 AND tenant_id IS NULL LIMIT 1", array(':slug' => $slug));
            if ($grants[$slug] === null) {
                $this->execute("INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) SELECT :role_id, id FROM `permissions`", array(':role_id' => $roleId));
                continue;
            }
            foreach ($grants[$slug] as $permissionSlug) {
                $this->execute(
                    "INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) SELECT :role_id, id FROM `permissions` WHERE slug = :slug",
                    array(':role_id' => $roleId, ':slug' => $permissionSlug)
                );
            }
        }
    }

    /**
     * Table definitions as of Phase 5 (database.sql, September 2026)
     * @return array table => column/key definitions
     */
    private function tableDefinitions() {
        return array(
            'saved_searches' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `module` varchar(50) NOT NULL COMMENT 'leads, clients, properties, deals, tasks',
            `name` varchar(255) NOT NULL,
            `filters` text NOT NULL COMMENT 'JSON encoded filter parameters',
            `is_default` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenant_id` (`tenant_id`),
            KEY `user_id` (`user_id`),
            KEY `idx_module` (`module`),
            KEY `idx_default` (`is_default`),
            CONSTRAINT `fk_savedsearch_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_savedsearch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
SQL
,
            'property_images' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `property_id` int(11) NOT NULL,
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(512) NOT NULL,
            `file_size` int(11) DEFAULT NULL,
            `mime_type` varchar(100) DEFAULT NULL,
            `is_primary` tinyint(1) DEFAULT 0,
            `display_order` int(11) DEFAULT 0,
            `caption` varchar(255) DEFAULT NULL,
            `uploaded_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenant_id` (`tenant_id`),
            KEY `property_id` (`property_id`),
            KEY `uploaded_by` (`uploaded_by`),
            KEY `idx_primary` (`is_primary`),
            KEY `idx_order` (`display_order`),
            CONSTRAINT `fk_propimg_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_propimg_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_propimg_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
SQL
,
            'webhooks' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `url` varchar(512) NOT NULL,
            `secret` varchar(64) DEFAULT NULL COMMENT 'Signing secret for verification',
            `events` text NOT NULL COMMENT 'Comma-separated list of subscribed events',
            `status` enum('active','inactive') DEFAULT 'active',
            `retry_enabled` tinyint(1) DEFAULT 1,
            `max_retries` int(11) DEFAULT 3,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenant_id` (`tenant_id`),
            KEY `created_by` (`created_by`),
            KEY `idx_status` (`status`),
            CONSTRAINT `fk_webhook_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_webhook_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
SQL
,
            'webhook_logs' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `webhook_id` int(11) NOT NULL,
            `event_type` varchar(100) NOT NULL,
            `payload` text DEFAULT NULL,
            `response_code` int(11) DEFAULT NULL,
            `response_body` text DEFAULT NULL,
            `attempt` int(11) DEFAULT 1,
            `status` enum('pending','success','failed','retrying') DEFAULT 'pending',
            `error_message` text DEFAULT NULL,
            `delivered_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `webhook_id` (`webhook_id`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_status` (`status`),
            KEY `idx_created` (`created_at`),
            CONSTRAINT `fk_log_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `webhooks` (`id`) ON DELETE CASCADE
SQL
,
            'workflows' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `trigger_type` enum('lead_created','lead_updated','lead_status_changed','client_created','property_created','deal_created','deal_status_changed','deal_won','deal_lost','task_created','task_completed','scheduled') NOT NULL,
            `trigger_config` text DEFAULT NULL COMMENT 'JSON configuration for trigger',
            `conditions` text DEFAULT NULL COMMENT 'JSON array of conditions',
            `status` enum('active','inactive') DEFAULT 'active',
            `execution_order` int(11) DEFAULT 0,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenant_id` (`tenant_id`),
            KEY `created_by` (`created_by`),
            KEY `idx_trigger` (`trigger_type`),
            KEY `idx_status` (`status`),
            CONSTRAINT `fk_workflow_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_workflow_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
SQL
,
            'workflow_actions' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `workflow_id` int(11) NOT NULL,
            `action_type` enum('send_email','create_task','update_field','assign_to_user','add_tag','send_webhook','wait','create_deal','send_notification') NOT NULL,
            `action_config` text NOT NULL COMMENT 'JSON configuration for action',
            `execution_order` int(11) DEFAULT 0,
            `delay_minutes` int(11) DEFAULT 0 COMMENT 'Delay before executing action',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `workflow_id` (`workflow_id`),
            KEY `idx_order` (`execution_order`),
            CONSTRAINT `fk_action_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
SQL
,
            'workflow_executions' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `workflow_id` int(11) NOT NULL,
            `trigger_data` text DEFAULT NULL COMMENT 'JSON data that triggered workflow',
            `status` enum('pending','running','completed','failed','cancelled') DEFAULT 'pending',
            `started_at` timestamp NULL DEFAULT NULL,
            `completed_at` timestamp NULL DEFAULT NULL,
            `error_message` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `workflow_id` (`workflow_id`),
            KEY `idx_status` (`status`),
            KEY `idx_created` (`created_at`),
            CONSTRAINT `fk_execution_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
SQL
,
            'workflow_action_logs' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `execution_id` int(11) NOT NULL,
            `action_id` int(11) NOT NULL,
            `status` enum('pending','completed','failed','skipped') DEFAULT 'pending',
            `result` text DEFAULT NULL COMMENT 'Action execution result',
            `error_message` text DEFAULT NULL,
            `executed_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `execution_id` (`execution_id`),
            KEY `action_id` (`action_id`),
            CONSTRAINT `fk_actionlog_execution` FOREIGN KEY (`execution_id`) REFERENCES `workflow_executions` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_actionlog_action` FOREIGN KEY (`action_id`) REFERENCES `workflow_actions` (`id`) ON DELETE CASCADE
SQL
,
            'permissions' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `slug` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `module` varchar(50) DEFAULT NULL COMMENT 'Module: leads, properties, deals, etc.',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`),
            KEY `idx_module` (`module`)
SQL
,
            'roles' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) DEFAULT NULL COMMENT 'NULL for system roles',
            `name` varchar(100) NOT NULL,
            `slug` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `is_system` tinyint(1) DEFAULT 0 COMMENT 'System roles cannot be deleted',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenant_id` (`tenant_id`),
            KEY `idx_slug` (`slug`),
            CONSTRAINT `fk_role_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
SQL
,
            'role_permissions' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `role_id` int(11) NOT NULL,
            `permission_id` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `role_permission` (`role_id`, `permission_id`),
            KEY `permission_id` (`permission_id`),
            CONSTRAINT `fk_roleperm_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_roleperm_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
SQL
,
            'user_roles' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `role_id` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_role` (`user_id`, `role_id`),
            KEY `role_id` (`role_id`),
            CONSTRAINT `fk_userrole_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_userrole_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
SQL
,
        );
    }
}

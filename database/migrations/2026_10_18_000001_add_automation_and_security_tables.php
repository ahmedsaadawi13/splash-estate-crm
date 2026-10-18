<?php
// FILE: /database/migrations/2026_10_18_000001_add_automation_and_security_tables.php

/**
 * Migration: AddAutomationAndSecurityTables
 *
 * Brings a Phase 5 (pre-October 2026) install up to the October 2026 database.sql:
 *   - tables: lead_scoring_rules, lead_scores, lead_score_history (if missing),
 *     notifications, password_resets, login_attempts
 *   - leads.tags, workflows.last_run_at
 *   - workflow_action_logs: scheduled_for, started_at, completed_at,
 *     status enum + 'scheduled', index idx_due
 *   - permissions workflows.manage, scoring.manage, audit.view, granted to the
 *     Tenant Admin (all three) and Sales Manager (workflows/scoring) system roles
 *   - user_roles rows for users that have none, from their legacy users.role
 *
 * Idempotent: running it on a database imported from the current database.sql
 * changes nothing.
 */

class AddAutomationAndSecurityTables extends Migration {

    public function up() {
        foreach ($this->tableDefinitions() as $table => $definition) {
            $this->createTable($table, $definition);
        }

        $this->addColumn('leads', 'tags', "varchar(500) DEFAULT NULL COMMENT 'Comma-separated tags' AFTER `notes`");
        $this->addColumn('workflows', 'last_run_at', "timestamp NULL DEFAULT NULL COMMENT 'Last run of a scheduled workflow' AFTER `execution_order`");

        // workflow_action_logs: delayed actions
        $type = (string)$this->columnType('workflow_action_logs', 'status');
        if (strpos($type, "'scheduled'") === false) {
            $this->modifyColumn('workflow_action_logs', 'status', "enum('pending','scheduled','completed','failed','skipped') DEFAULT 'pending'");
        }
        $this->addColumn('workflow_action_logs', 'scheduled_for', "datetime DEFAULT NULL COMMENT 'When a delayed action is due (status=scheduled)' AFTER `error_message`");
        $this->addColumn('workflow_action_logs', 'started_at', "timestamp NULL DEFAULT NULL AFTER `scheduled_for`");
        $this->addColumn('workflow_action_logs', 'completed_at', "timestamp NULL DEFAULT NULL AFTER `started_at`");
        $this->addIndex('workflow_action_logs', 'idx_due', array('status', 'scheduled_for'));

        // New permissions
        $permissions = array(
            array('Manage Workflows', 'workflows.manage', 'Create and manage workflow automations', 'automation'),
            array('Manage Lead Scoring', 'scoring.manage', 'Configure lead scoring rules', 'automation'),
            array('View Audit Log', 'audit.view', 'View the activity/audit log', 'settings')
        );
        foreach ($permissions as $p) {
            $this->execute(
                "INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `module`) VALUES (:name, :slug, :description, :module)",
                array(':name' => $p[0], ':slug' => $p[1], ':description' => $p[2], ':module' => $p[3])
            );
        }
        $this->grant('tenant_admin', array('workflows.manage', 'scoring.manage', 'audit.view'));
        $this->grant('sales_manager', array('workflows.manage', 'scoring.manage'));

        // Explicit RBAC roles for users that only have the legacy users.role
        $this->execute(
            "INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
             SELECT u.id, r.id FROM `users` u
             INNER JOIN `roles` r ON r.slug = u.role AND r.is_system = 1 AND r.tenant_id IS NULL
             WHERE u.role IN ('tenant_admin', 'agent')
             AND NOT EXISTS (SELECT 1 FROM `user_roles` ur WHERE ur.user_id = u.id)"
        );

        return true;
    }

    public function down() {
        // Permissions (role_permissions rows cascade)
        $this->execute("DELETE FROM `permissions` WHERE slug IN ('workflows.manage', 'scoring.manage', 'audit.view')");

        $this->dropIndex('workflow_action_logs', 'idx_due');
        $this->dropColumn('workflow_action_logs', 'completed_at');
        $this->dropColumn('workflow_action_logs', 'started_at');
        $this->dropColumn('workflow_action_logs', 'scheduled_for');
        if (strpos((string)$this->columnType('workflow_action_logs', 'status'), "'scheduled'") !== false) {
            $this->execute("UPDATE `workflow_action_logs` SET status = 'pending' WHERE status = 'scheduled'");
            $this->modifyColumn('workflow_action_logs', 'status', "enum('pending','completed','failed','skipped') DEFAULT 'pending'");
        }
        $this->dropColumn('workflows', 'last_run_at');
        $this->dropColumn('leads', 'tags');

        $this->dropTable('login_attempts');
        $this->dropTable('password_resets');
        $this->dropTable('notifications');

        // lead_scoring_* tables and user_roles rows are kept: they may predate this
        // migration and hold data; the legacy role fallback makes user_roles harmless.
        return true;
    }

    /**
     * Grant permissions to a system role (if the role exists)
     */
    private function grant($roleSlug, $permissionSlugs) {
        $roleId = $this->fetchValue("SELECT id FROM `roles` WHERE slug = :slug AND is_system = 1 AND tenant_id IS NULL LIMIT 1", array(':slug' => $roleSlug));
        if (!$roleId) {
            return;
        }
        foreach ($permissionSlugs as $slug) {
            $this->execute(
                "INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) SELECT :role_id, id FROM `permissions` WHERE slug = :slug",
                array(':role_id' => (int)$roleId, ':slug' => $slug)
            );
        }
    }

    /**
     * Table definitions from database.sql (October 2026)
     * @return array table => column/key definitions
     */
    private function tableDefinitions() {
        return array(
            'lead_scoring_rules' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `rule_type` enum('demographic','behavioral','engagement','custom') NOT NULL COMMENT 'Type of scoring rule',
            `field_name` varchar(100) NOT NULL COMMENT 'Field to evaluate (e.g., source, status, budget)',
            `operator` enum('equals','not_equals','contains','not_contains','greater_than','less_than','greater_than_or_equal','less_than_or_equal','is_empty','is_not_empty','in','not_in') NOT NULL,
            `field_value` varchar(255) DEFAULT NULL COMMENT 'Value to compare against',
            `points` int(11) NOT NULL DEFAULT 0 COMMENT 'Points to add/subtract when rule matches',
            `status` enum('active','inactive') DEFAULT 'active',
            `execution_order` int(11) DEFAULT 0 COMMENT 'Order of rule execution',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenant_id` (`tenant_id`),
            KEY `idx_status` (`status`),
            KEY `idx_rule_type` (`rule_type`),
            CONSTRAINT `fk_scoring_rule_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
SQL
,
            'lead_scores' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `lead_id` int(11) NOT NULL,
            `score` int(11) NOT NULL DEFAULT 0 COMMENT 'Total calculated score',
            `grade` enum('A','B','C','D','F') DEFAULT NULL COMMENT 'Letter grade based on score',
            `score_breakdown` text DEFAULT NULL COMMENT 'JSON breakdown of score by rule',
            `last_calculated_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `lead_id` (`lead_id`),
            KEY `idx_score` (`score`),
            KEY `idx_grade` (`grade`),
            CONSTRAINT `fk_lead_score_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
SQL
,
            'lead_score_history' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `lead_id` int(11) NOT NULL,
            `old_score` int(11) DEFAULT 0,
            `new_score` int(11) NOT NULL,
            `old_grade` enum('A','B','C','D','F') DEFAULT NULL,
            `new_grade` enum('A','B','C','D','F') DEFAULT NULL,
            `changed_by` varchar(100) DEFAULT NULL COMMENT 'What triggered the change (rule_id, manual, import)',
            `change_reason` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `lead_id` (`lead_id`),
            KEY `idx_created_at` (`created_at`),
            CONSTRAINT `fk_score_history_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
SQL
,
            'notifications' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `type` varchar(50) DEFAULT 'info' COMMENT 'info, success, warning, task, lead, deal, workflow',
            `title` varchar(255) NOT NULL,
            `message` text DEFAULT NULL,
            `link` varchar(512) DEFAULT NULL COMMENT 'Relative app path, e.g. leads/view/5',
            `is_read` tinyint(1) DEFAULT 0,
            `read_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenant_id` (`tenant_id`),
            KEY `idx_user_unread` (`user_id`, `is_read`),
            CONSTRAINT `fk_notification_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
SQL
,
            'password_resets' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `token_hash` char(64) NOT NULL,
            `expires_at` datetime NOT NULL,
            `used_at` datetime DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token_hash` (`token_hash`),
            KEY `user_id` (`user_id`),
            CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
SQL
,
            'login_attempts' => <<<'SQL'

            `id` int(11) NOT NULL AUTO_INCREMENT,
            `email` varchar(255) NOT NULL,
            `ip_address` varchar(45) NOT NULL,
            `success` tinyint(1) DEFAULT 0,
            `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_email_time` (`email`, `attempted_at`),
            KEY `idx_ip_time` (`ip_address`, `attempted_at`)
SQL
,
        );
    }
}

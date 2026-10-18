-- FILE: /database.sql
-- SplashEstate CRM - Database Schema
-- Multi-tenant Real Estate CRM System

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Database Creation
-- --------------------------------------------------------

CREATE DATABASE IF NOT EXISTS `splashestate_crm` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `splashestate_crm`;

-- --------------------------------------------------------
-- Table: tenants
-- Stores tenant/agency information
-- --------------------------------------------------------

CREATE TABLE `tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `api_key` varchar(64) NOT NULL,
  `stripe_customer_id` varchar(255) DEFAULT NULL COMMENT 'Stripe customer ID',
  `stripe_subscription_id` varchar(255) DEFAULT NULL COMMENT 'Stripe subscription ID',
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `idx_status` (`status`),
  KEY `idx_stripe_customer` (`stripe_customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: plans
-- Subscription plans with resource limits
-- --------------------------------------------------------

CREATE TABLE `plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` enum('monthly','yearly') DEFAULT 'monthly',
  `stripe_price_id` varchar(255) DEFAULT NULL COMMENT 'Stripe Price ID for billing',
  `max_leads` int(11) DEFAULT -1 COMMENT '-1 = unlimited',
  `max_properties` int(11) DEFAULT -1,
  `max_agents` int(11) DEFAULT -1,
  `features` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_stripe_price` (`stripe_price_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: tenant_subscriptions
-- Tenant subscription to plans
-- --------------------------------------------------------

CREATE TABLE `tenant_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `status` enum('active','cancelled','expired','suspended') DEFAULT 'active',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `auto_renew` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `plan_id` (`plan_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_sub_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: users
-- System users with role-based access
-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `role` enum('platform_admin','tenant_admin','agent') DEFAULT 'agent',
  `avatar` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `tenant_id` (`tenant_id`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_user_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: leads
-- Lead/prospect management
-- --------------------------------------------------------

CREATE TABLE `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `source` varchar(100) DEFAULT NULL COMMENT 'Website, Referral, API, etc.',
  `status` enum('new','contacted','qualified','proposal','negotiation','won','lost') DEFAULT 'new',
  `interest_type` enum('buy','sell','rent','lease') DEFAULT NULL,
  `budget_min` decimal(15,2) DEFAULT NULL,
  `budget_max` decimal(15,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL COMMENT 'Comma-separated tags',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_lead_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lead_user` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: clients
-- Converted leads become clients
-- --------------------------------------------------------

CREATE TABLE `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `phone_secondary` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `zip` varchar(20) DEFAULT NULL,
  `client_type` enum('buyer','seller','landlord','tenant') DEFAULT 'buyer',
  `status` enum('active','inactive') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `lead_id` (`lead_id`),
  KEY `idx_client_type` (`client_type`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_client_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: properties
-- Property listings
-- --------------------------------------------------------

CREATE TABLE `properties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `listing_agent_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `property_type` enum('residential','commercial','land') DEFAULT 'residential',
  `sub_type` varchar(100) DEFAULT NULL COMMENT 'House, Condo, Office, etc.',
  `listing_type` enum('sale','rent','lease') DEFAULT 'sale',
  `price` decimal(15,2) NOT NULL,
  `bedrooms` int(11) DEFAULT NULL,
  `bathrooms` decimal(3,1) DEFAULT NULL,
  `square_feet` int(11) DEFAULT NULL,
  `lot_size` decimal(10,2) DEFAULT NULL,
  `year_built` int(11) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL,
  `zip` varchar(20) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `status` enum('available','pending','sold','rented','off_market') DEFAULT 'available',
  `featured_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `owner_id` (`owner_id`),
  KEY `listing_agent_id` (`listing_agent_id`),
  KEY `idx_property_type` (`property_type`),
  KEY `idx_listing_type` (`listing_type`),
  KEY `idx_status` (`status`),
  KEY `idx_price` (`price`),
  KEY `idx_city` (`city`),
  CONSTRAINT `fk_property_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_property_owner` FOREIGN KEY (`owner_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_property_agent` FOREIGN KEY (`listing_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: deals
-- Deal pipeline management
-- --------------------------------------------------------

CREATE TABLE `deals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `property_id` int(11) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `deal_value` decimal(15,2) DEFAULT NULL,
  `commission` decimal(15,2) DEFAULT NULL,
  `stage` enum('lead','qualified','viewing','offer','negotiation','contract','closed_won','closed_lost') DEFAULT 'lead',
  `probability` int(11) DEFAULT 0 COMMENT 'Percentage 0-100',
  `expected_close_date` date DEFAULT NULL,
  `actual_close_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `client_id` (`client_id`),
  KEY `property_id` (`property_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `idx_stage` (`stage`),
  CONSTRAINT `fk_deal_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_deal_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deal_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deal_user` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: tasks
-- Task management
-- --------------------------------------------------------

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `related_to_type` enum('lead','client','property','deal') DEFAULT NULL,
  `related_to_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `created_by` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_priority` (`priority`),
  CONSTRAINT `fk_task_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: activities
-- Activity tracking (calls, meetings, emails)
-- --------------------------------------------------------

CREATE TABLE `activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `activity_type` enum('call','meeting','email','note') NOT NULL,
  `related_to_type` enum('lead','client','property','deal') DEFAULT NULL,
  `related_to_id` int(11) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_scheduled` (`scheduled_at`),
  CONSTRAINT `fk_activity_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: attachments
-- File attachments for various entities
-- --------------------------------------------------------

CREATE TABLE `attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `related_to_type` enum('lead','client','property','deal','task') NOT NULL,
  `related_to_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_related` (`related_to_type`, `related_to_id`),
  CONSTRAINT `fk_attachment_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attachment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: invoices
-- Billing invoices
-- --------------------------------------------------------

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `tax` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL,
  `status` enum('pending','paid','overdue','cancelled') DEFAULT 'pending',
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `paid_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `tenant_id` (`tenant_id`),
  KEY `subscription_id` (`subscription_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_invoice_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `tenant_subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: payments
-- Payment records
-- --------------------------------------------------------

CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `status` enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `payment_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_payment_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: activity_logs
-- System activity logging
-- --------------------------------------------------------

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: saved_searches
-- Stores user saved search filters
-- --------------------------------------------------------

CREATE TABLE `saved_searches` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: property_images
-- Stores property images for gallery
-- --------------------------------------------------------

CREATE TABLE `property_images` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: webhooks
-- Webhook endpoint configurations
-- --------------------------------------------------------

CREATE TABLE `webhooks` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: webhook_logs
-- Webhook delivery attempt logs
-- --------------------------------------------------------

CREATE TABLE `webhook_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: workflows
-- Automated workflow definitions
-- --------------------------------------------------------

CREATE TABLE `workflows` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `trigger_type` enum('lead_created','lead_updated','lead_status_changed','client_created','property_created','deal_created','deal_status_changed','deal_won','deal_lost','task_created','task_completed','scheduled') NOT NULL,
  `trigger_config` text DEFAULT NULL COMMENT 'JSON configuration for trigger',
  `conditions` text DEFAULT NULL COMMENT 'JSON array of conditions',
  `status` enum('active','inactive') DEFAULT 'active',
  `execution_order` int(11) DEFAULT 0,
  `last_run_at` timestamp NULL DEFAULT NULL COMMENT 'Last run of a scheduled workflow',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: workflow_actions
-- Actions to execute when workflow triggers
-- --------------------------------------------------------

CREATE TABLE `workflow_actions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: workflow_executions
-- Log of workflow execution instances
-- --------------------------------------------------------

CREATE TABLE `workflow_executions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: workflow_action_logs
-- Detailed log of individual action executions
-- --------------------------------------------------------

CREATE TABLE `workflow_action_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `execution_id` int(11) NOT NULL,
  `action_id` int(11) NOT NULL,
  `status` enum('pending','scheduled','completed','failed','skipped') DEFAULT 'pending',
  `result` text DEFAULT NULL COMMENT 'Action execution result',
  `error_message` text DEFAULT NULL,
  `scheduled_for` datetime DEFAULT NULL COMMENT 'When a delayed action is due (status=scheduled)',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `executed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `execution_id` (`execution_id`),
  KEY `action_id` (`action_id`),
  KEY `idx_due` (`status`, `scheduled_for`),
  CONSTRAINT `fk_actionlog_execution` FOREIGN KEY (`execution_id`) REFERENCES `workflow_executions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_actionlog_action` FOREIGN KEY (`action_id`) REFERENCES `workflow_actions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: lead_scoring_rules
-- Define scoring rules for lead qualification
-- --------------------------------------------------------

CREATE TABLE `lead_scoring_rules` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: lead_scores
-- Stores calculated scores for each lead
-- --------------------------------------------------------

CREATE TABLE `lead_scores` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: lead_score_history
-- Audit trail of score changes
-- --------------------------------------------------------

CREATE TABLE `lead_score_history` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: notifications
-- In-app notifications for users
-- --------------------------------------------------------

CREATE TABLE `notifications` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: password_resets
-- One-time password reset tokens (only the SHA-256 hash is stored)
-- --------------------------------------------------------

CREATE TABLE `password_resets` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: stripe_events
-- Processed Stripe webhook event IDs (idempotent event handling)
-- --------------------------------------------------------

CREATE TABLE `stripe_events` (
  `event_id` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `processed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_processed` (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: login_attempts
-- Login attempt tracking for brute-force protection
-- --------------------------------------------------------

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_time` (`email`, `attempted_at`),
  KEY `idx_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: permissions
-- System permissions for granular access control
-- --------------------------------------------------------

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `module` varchar(50) DEFAULT NULL COMMENT 'Module: leads, properties, deals, etc.',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: roles
-- Custom roles for flexible permission assignment
-- --------------------------------------------------------

CREATE TABLE `roles` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: role_permissions
-- Maps permissions to roles
-- --------------------------------------------------------

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permission` (`role_id`, `permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `fk_roleperm_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_roleperm_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: user_roles
-- Assigns roles to users
-- --------------------------------------------------------

CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_role` (`user_id`, `role_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `fk_userrole_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_userrole_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Seed Data
-- --------------------------------------------------------

-- Insert default plans
INSERT INTO `plans` (`name`, `slug`, `description`, `price`, `billing_cycle`, `stripe_price_id`, `max_leads`, `max_properties`, `max_agents`, `features`, `status`) VALUES
('Free Trial', 'free-trial', 'Perfect for getting started', 0.00, 'monthly', NULL, 50, 10, 2, 'Basic features, Email support', 'active'),
('Professional', 'professional', 'For growing agencies', 49.00, 'monthly', 'price_professional_monthly', 500, 100, 10, 'All features, Priority support, Custom reports', 'active'),
('Enterprise', 'enterprise', 'For large organizations', 199.00, 'monthly', 'price_enterprise_monthly', -1, -1, -1, 'Unlimited everything, 24/7 support, API access, Custom integrations', 'active');

-- Insert platform admin user
-- Password: admin123 (hashed with password_hash)
INSERT INTO `users` (`tenant_id`, `email`, `password`, `first_name`, `last_name`, `role`, `status`) VALUES
(NULL, 'admin@splashestate.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Platform', 'Admin', 'platform_admin', 'active');

-- Insert demo tenants
INSERT INTO `tenants` (`name`, `slug`, `email`, `phone`, `address`, `api_key`, `status`) VALUES
('Sunset Realty Group', 'sunset-realty', 'info@sunsetrealty.com', '(555) 123-4567', '123 Main St, Los Angeles, CA 90001', 'sk_test_4eC39HqLyjWDarjtT1zdp7dc11111111111111111111111111111111', 'active'),
('Metro Properties Inc', 'metro-properties', 'contact@metroproperties.com', '(555) 987-6543', '456 Market St, San Francisco, CA 94102', 'sk_test_7uH92KfDjkPNxywqR6vbm3ef22222222222222222222222222222222', 'active');

-- Insert tenant subscriptions
INSERT INTO `tenant_subscriptions` (`tenant_id`, `plan_id`, `status`, `start_date`, `end_date`, `auto_renew`) VALUES
(1, 2, 'active', '2025-01-01', NULL, 1),
(2, 3, 'active', '2025-01-01', NULL, 1);

-- Insert demo users/agents for Sunset Realty
-- Password for all: agent123
INSERT INTO `users` (`tenant_id`, `email`, `password`, `first_name`, `last_name`, `phone`, `role`, `status`) VALUES
(1, 'john.smith@sunsetrealty.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John', 'Smith', '(555) 111-2222', 'tenant_admin', 'active'),
(1, 'sarah.johnson@sunsetrealty.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sarah', 'Johnson', '(555) 111-3333', 'agent', 'active'),
(1, 'mike.davis@sunsetrealty.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mike', 'Davis', '(555) 111-4444', 'agent', 'active');

-- Insert demo users/agents for Metro Properties
INSERT INTO `users` (`tenant_id`, `email`, `password`, `first_name`, `last_name`, `phone`, `role`, `status`) VALUES
(2, 'lisa.chen@metroproperties.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Lisa', 'Chen', '(555) 222-2222', 'tenant_admin', 'active'),
(2, 'robert.williams@metroproperties.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Robert', 'Williams', '(555) 222-3333', 'agent', 'active'),
(2, 'emily.brown@metroproperties.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Emily', 'Brown', '(555) 222-4444', 'agent', 'active');

-- Insert demo leads for Sunset Realty
INSERT INTO `leads` (`tenant_id`, `assigned_to`, `first_name`, `last_name`, `email`, `phone`, `source`, `status`, `interest_type`, `budget_min`, `budget_max`, `notes`) VALUES
(1, 2, 'David', 'Anderson', 'david.anderson@email.com', '(555) 301-1111', 'Website', 'new', 'buy', 400000.00, 500000.00, 'Looking for a 3-bedroom house in LA area'),
(1, 3, 'Jennifer', 'Martinez', 'jennifer.m@email.com', '(555) 301-2222', 'Referral', 'contacted', 'sell', NULL, NULL, 'Wants to sell current property'),
(1, 4, 'Michael', 'Taylor', 'michael.t@email.com', '(555) 301-3333', 'API', 'qualified', 'rent', 2000.00, 3000.00, 'Needs apartment near downtown'),
(1, 2, 'Jessica', 'Wilson', 'jessica.w@email.com', '(555) 301-4444', 'Website', 'new', 'buy', 600000.00, 800000.00, 'Interested in luxury properties');

-- Insert demo leads for Metro Properties
INSERT INTO `leads` (`tenant_id`, `assigned_to`, `first_name`, `last_name`, `email`, `phone`, `source`, `status`, `interest_type`, `budget_min`, `budget_max`, `notes`) VALUES
(2, 5, 'Christopher', 'Moore', 'chris.moore@email.com', '(555) 401-1111', 'Website', 'contacted', 'buy', 700000.00, 900000.00, 'Looking for commercial property'),
(2, 6, 'Amanda', 'Thomas', 'amanda.t@email.com', '(555) 401-2222', 'Referral', 'qualified', 'lease', NULL, NULL, 'Office space required'),
(2, 7, 'Daniel', 'Jackson', 'daniel.j@email.com', '(555) 401-3333', 'API', 'new', 'buy', 500000.00, 650000.00, 'First-time homebuyer');

-- Insert demo clients for Sunset Realty
INSERT INTO `clients` (`tenant_id`, `lead_id`, `first_name`, `last_name`, `email`, `phone`, `address`, `city`, `state`, `zip`, `client_type`, `status`) VALUES
(1, 3, 'Michael', 'Taylor', 'michael.t@email.com', '(555) 301-3333', '789 Oak Ave', 'Los Angeles', 'CA', '90015', 'buyer', 'active'),
(1, NULL, 'Patricia', 'Garcia', 'patricia.g@email.com', '(555) 301-5555', '321 Pine St', 'Los Angeles', 'CA', '90012', 'seller', 'active');

-- Insert demo clients for Metro Properties
INSERT INTO `clients` (`tenant_id`, `lead_id`, `first_name`, `last_name`, `email`, `phone`, `address`, `city`, `state`, `zip`, `client_type`, `status`) VALUES
(2, 6, 'Amanda', 'Thomas', 'amanda.t@email.com', '(555) 401-2222', '654 Market Blvd', 'San Francisco', 'CA', '94103', 'tenant', 'active');

-- Insert demo properties for Sunset Realty
INSERT INTO `properties` (`tenant_id`, `owner_id`, `listing_agent_id`, `title`, `description`, `property_type`, `sub_type`, `listing_type`, `price`, `bedrooms`, `bathrooms`, `square_feet`, `lot_size`, `year_built`, `address`, `city`, `state`, `zip`, `status`) VALUES
(1, 2, 2, 'Beautiful 3BR Family Home', 'Spacious family home with modern amenities and great location', 'residential', 'House', 'sale', 475000.00, 3, 2.5, 2100, 5000.00, 2015, '123 Elm Street', 'Los Angeles', 'CA', '90001', 'available'),
(1, NULL, 3, 'Modern Downtown Condo', 'Luxury condo in the heart of downtown with stunning views', 'residential', 'Condo', 'sale', 625000.00, 2, 2.0, 1400, NULL, 2020, '456 City Center Dr', 'Los Angeles', 'CA', '90013', 'available'),
(1, 2, 4, 'Charming Bungalow', 'Cozy bungalow perfect for first-time buyers', 'residential', 'House', 'sale', 385000.00, 2, 1.0, 1200, 3500.00, 1985, '789 Maple Ave', 'Los Angeles', 'CA', '90025', 'pending');

-- Insert demo properties for Metro Properties
INSERT INTO `properties` (`tenant_id`, `owner_id`, `listing_agent_id`, `title`, `description`, `property_type`, `sub_type`, `listing_type`, `price`, `bedrooms`, `bathrooms`, `square_feet`, `lot_size`, `year_built`, `address`, `city`, `state`, `zip`, `status`) VALUES
(2, 3, 5, 'Prime Commercial Space', 'Excellent location for retail or office use', 'commercial', 'Office', 'lease', 8500.00, NULL, 2.0, 3500, NULL, 2010, '100 Business Park Way', 'San Francisco', 'CA', '94105', 'available'),
(2, NULL, 6, 'Luxury Penthouse', 'Stunning penthouse with panoramic bay views', 'residential', 'Condo', 'sale', 1250000.00, 3, 3.0, 2800, NULL, 2022, '200 Skyline Tower', 'San Francisco', 'CA', '94111', 'available');

-- Insert demo deals for Sunset Realty
INSERT INTO `deals` (`tenant_id`, `client_id`, `property_id`, `assigned_to`, `title`, `deal_value`, `commission`, `stage`, `probability`, `expected_close_date`) VALUES
(1, 1, 1, 2, 'Taylor Family Home Purchase', 475000.00, 14250.00, 'viewing', 60, '2025-03-15'),
(1, 2, 3, 4, 'Garcia Property Sale', 385000.00, 11550.00, 'negotiation', 75, '2025-02-28');

-- Insert demo deals for Metro Properties
INSERT INTO `deals` (`tenant_id`, `client_id`, `property_id`, `assigned_to`, `title`, `deal_value`, `commission`, `stage`, `probability`, `expected_close_date`) VALUES
(2, 3, 4, 5, 'Thomas Office Lease', 102000.00, 5100.00, 'contract', 85, '2025-02-15');

-- Insert demo tasks for Sunset Realty
INSERT INTO `tasks` (`tenant_id`, `assigned_to`, `created_by`, `related_to_type`, `related_to_id`, `title`, `description`, `due_date`, `priority`, `status`) VALUES
(1, 2, 2, 'lead', 1, 'Follow up with David Anderson', 'Schedule property viewing', '2025-01-25 10:00:00', 'high', 'pending'),
(1, 3, 2, 'deal', 1, 'Prepare purchase agreement', 'Draft documents for Taylor deal', '2025-01-28 14:00:00', 'urgent', 'in_progress'),
(1, 4, 2, 'property', 3, 'Property inspection', 'Coordinate inspection for Maple Ave property', '2025-01-30 09:00:00', 'medium', 'pending');

-- Insert demo tasks for Metro Properties
INSERT INTO `tasks` (`tenant_id`, `assigned_to`, `created_by`, `related_to_type`, `related_to_id`, `title`, `description`, `due_date`, `priority`, `status`) VALUES
(2, 5, 5, 'lead', 5, 'Call Christopher Moore', 'Discuss commercial property requirements', '2025-01-26 11:00:00', 'high', 'pending'),
(2, 6, 5, 'deal', 3, 'Finalize lease terms', 'Review and send final lease agreement', '2025-01-27 15:00:00', 'urgent', 'in_progress');

-- Insert demo activities
INSERT INTO `activities` (`tenant_id`, `user_id`, `activity_type`, `related_to_type`, `related_to_id`, `subject`, `description`, `scheduled_at`, `completed`) VALUES
(1, 2, 'call', 'lead', 1, 'Initial contact call', 'Discussed property requirements and budget', '2025-01-20 14:30:00', 1),
(1, 3, 'meeting', 'client', 1, 'Property viewing', 'Showed 3 properties in LA area', '2025-01-22 10:00:00', 1),
(2, 5, 'email', 'lead', 5, 'Sent property listings', 'Emailed commercial property options', '2025-01-21 09:15:00', 1);

-- Insert demo invoices
INSERT INTO `invoices` (`tenant_id`, `subscription_id`, `invoice_number`, `amount`, `tax`, `total`, `status`, `issue_date`, `due_date`, `paid_date`) VALUES
(1, 1, 'INV-2025-001', 49.00, 3.43, 52.43, 'paid', '2025-01-01', '2025-01-15', '2025-01-10'),
(2, 2, 'INV-2025-002', 199.00, 13.93, 212.93, 'paid', '2025-01-01', '2025-01-15', '2025-01-12');

-- Insert demo payments
INSERT INTO `payments` (`tenant_id`, `invoice_id`, `amount`, `payment_method`, `transaction_id`, `status`, `payment_date`) VALUES
(1, 1, 52.43, 'credit_card', 'txn_1234567890', 'completed', '2025-01-10 15:30:00'),
(2, 2, 212.93, 'credit_card', 'txn_0987654321', 'completed', '2025-01-12 11:20:00');

-- Insert default permissions
INSERT INTO `permissions` (`name`, `slug`, `description`, `module`) VALUES
-- Lead permissions
('View Leads', 'leads.view', 'View leads list and details', 'leads'),
('Create Leads', 'leads.create', 'Create new leads', 'leads'),
('Edit Leads', 'leads.edit', 'Edit existing leads', 'leads'),
('Delete Leads', 'leads.delete', 'Delete leads', 'leads'),
('Export Leads', 'leads.export', 'Export leads data', 'leads'),

-- Client permissions
('View Clients', 'clients.view', 'View clients list and details', 'clients'),
('Create Clients', 'clients.create', 'Create new clients', 'clients'),
('Edit Clients', 'clients.edit', 'Edit existing clients', 'clients'),
('Delete Clients', 'clients.delete', 'Delete clients', 'clients'),

-- Property permissions
('View Properties', 'properties.view', 'View properties list and details', 'properties'),
('Create Properties', 'properties.create', 'Create new properties', 'properties'),
('Edit Properties', 'properties.edit', 'Edit existing properties', 'properties'),
('Delete Properties', 'properties.delete', 'Delete properties', 'properties'),
('Manage Property Images', 'properties.images', 'Upload and manage property images', 'properties'),

-- Deal permissions
('View Deals', 'deals.view', 'View deals list and details', 'deals'),
('Create Deals', 'deals.create', 'Create new deals', 'deals'),
('Edit Deals', 'deals.edit', 'Edit existing deals', 'deals'),
('Delete Deals', 'deals.delete', 'Delete deals', 'deals'),

-- Task permissions
('View Tasks', 'tasks.view', 'View tasks list and details', 'tasks'),
('Create Tasks', 'tasks.create', 'Create new tasks', 'tasks'),
('Edit Tasks', 'tasks.edit', 'Edit existing tasks', 'tasks'),
('Delete Tasks', 'tasks.delete', 'Delete tasks', 'tasks'),

-- Report permissions
('View Reports', 'reports.view', 'Access reports and analytics', 'reports'),
('Export Reports', 'reports.export', 'Export report data', 'reports'),

-- Settings permissions
('Manage Settings', 'settings.manage', 'Manage system settings', 'settings'),
('Manage Users', 'users.manage', 'Manage users and agents', 'settings'),
('Manage Roles', 'roles.manage', 'Manage roles and permissions', 'settings'),
('Manage Webhooks', 'webhooks.manage', 'Configure webhooks', 'settings'),
('View Subscription', 'subscription.view', 'View subscription details', 'settings'),
('Manage Subscription', 'subscription.manage', 'Upgrade/downgrade subscription', 'settings'),

-- Automation permissions
('Manage Workflows', 'workflows.manage', 'Create and manage workflow automations', 'automation'),
('Manage Lead Scoring', 'scoring.manage', 'Configure lead scoring rules', 'automation'),
('View Audit Log', 'audit.view', 'View the activity/audit log', 'settings');

-- Insert default system roles
INSERT INTO `roles` (`tenant_id`, `name`, `slug`, `description`, `is_system`) VALUES
(NULL, 'Tenant Admin', 'tenant_admin', 'Full access to tenant resources', 1),
(NULL, 'Agent', 'agent', 'Standard agent access', 1),
(NULL, 'Sales Manager', 'sales_manager', 'Manage sales team and deals', 1),
(NULL, 'Viewer', 'viewer', 'Read-only access', 1);

-- Assign permissions to Tenant Admin role (all permissions)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM permissions;

-- Assign permissions to Agent role
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM permissions WHERE slug IN (
    'leads.view', 'leads.create', 'leads.edit',
    'clients.view', 'clients.create', 'clients.edit',
    'properties.view',
    'deals.view', 'deals.create', 'deals.edit',
    'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete',
    'reports.view'
);

-- Assign permissions to Sales Manager role
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, id FROM permissions WHERE slug IN (
    'leads.view', 'leads.create', 'leads.edit', 'leads.delete', 'leads.export',
    'clients.view', 'clients.create', 'clients.edit',
    'properties.view',
    'deals.view', 'deals.create', 'deals.edit', 'deals.delete',
    'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete',
    'reports.view', 'reports.export',
    'users.manage',
    'workflows.manage', 'scoring.manage'
);

-- Assign permissions to Viewer role
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, id FROM permissions WHERE slug IN (
    'leads.view',
    'clients.view',
    'properties.view',
    'deals.view',
    'tasks.view',
    'reports.view'
);

-- End of seed data

-- Assign RBAC roles to demo users based on their legacy role
-- (users without any user_roles row fall back to their users.role, see can())
INSERT INTO `user_roles` (`user_id`, `role_id`)
SELECT u.id, r.id FROM users u
INNER JOIN roles r ON r.slug = u.role AND r.is_system = 1
WHERE u.role IN ('tenant_admin', 'agent');

-- Default lead scoring rules for demo tenants
INSERT INTO `lead_scoring_rules` (`tenant_id`, `name`, `description`, `rule_type`, `field_name`, `operator`, `field_value`, `points`, `execution_order`)
SELECT t.id, r.name, r.description, r.rule_type, r.field_name, r.operator, r.field_value, r.points, r.execution_order
FROM tenants t
CROSS JOIN (
    SELECT 'Has email address' AS name, 'Lead can be contacted by email' AS description, 'demographic' AS rule_type, 'email' AS field_name, 'is_not_empty' AS operator, NULL AS field_value, 10 AS points, 1 AS execution_order
    UNION ALL SELECT 'Has phone number', 'Lead can be called', 'demographic', 'phone', 'is_not_empty', NULL, 10, 2
    UNION ALL SELECT 'High budget (500k+)', 'Maximum budget of at least 500,000', 'demographic', 'budget_max', 'greater_than_or_equal', '500000', 25, 3
    UNION ALL SELECT 'Referral source', 'Referred leads convert better', 'behavioral', 'source', 'equals', 'Referral', 20, 4
    UNION ALL SELECT 'Wants to buy', 'Buyer intent', 'behavioral', 'interest_type', 'equals', 'buy', 15, 5
    UNION ALL SELECT 'Qualified or beyond', 'Lead progressed in the pipeline', 'engagement', 'status', 'in', 'qualified,proposal,negotiation', 20, 6
) r;

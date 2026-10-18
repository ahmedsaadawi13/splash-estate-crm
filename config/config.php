<?php
// FILE: /config/config.php

/**
 * SplashEstate CRM - Configuration File
 * Loads environment variables and defines constants
 */

// Load environment variables from .env file
if (file_exists(dirname(__DIR__) . '/.env')) {
    $lines = file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse key=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if (preg_match('/^(["\'])(.*)\1$/', $value, $matches)) {
                $value = $matches[2];
            }

            // Convert boolean-like strings so "false" is not truthy
            $lower = strtolower($value);
            if ($lower === 'true') {
                $value = true;
            } elseif ($lower === 'false') {
                $value = false;
            }

            // Set as constant if not already defined
            if ($key !== '' && !defined($key)) {
                define($key, $value);
            }
        }
    }
}

// Database Configuration
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'splashestate_crm');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

// Application Configuration
if (!defined('APP_NAME')) define('APP_NAME', 'SplashEstate CRM');
if (!defined('APP_ENV')) define('APP_ENV', 'development');
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);
if (!defined('BASE_URL')) define('BASE_URL', 'http://localhost/SplashEstateCRM/public');

// Session Configuration
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 7200);

// Upload Configuration
if (!defined('MAX_UPLOAD_SIZE')) define('MAX_UPLOAD_SIZE', 5242880); // 5MB
if (!defined('ALLOWED_FILE_TYPES')) define('ALLOWED_FILE_TYPES', 'jpg,jpeg,png,pdf,doc,docx,xls,xlsx');

// Email Configuration
if (!defined('MAIL_DRIVER')) define('MAIL_DRIVER', 'smtp');
if (!defined('MAIL_HOST')) define('MAIL_HOST', 'smtp.gmail.com');
if (!defined('MAIL_PORT')) define('MAIL_PORT', '587');
if (!defined('MAIL_USERNAME')) define('MAIL_USERNAME', '');
if (!defined('MAIL_PASSWORD')) define('MAIL_PASSWORD', '');
if (!defined('MAIL_ENCRYPTION')) define('MAIL_ENCRYPTION', 'tls');
if (!defined('MAIL_FROM_ADDRESS')) define('MAIL_FROM_ADDRESS', 'noreply@splashestate.com');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'SplashEstate CRM');

// Stripe Configuration
if (!defined('STRIPE_PUBLISHABLE_KEY')) define('STRIPE_PUBLISHABLE_KEY', '');
if (!defined('STRIPE_SECRET_KEY')) define('STRIPE_SECRET_KEY', '');
if (!defined('STRIPE_WEBHOOK_SECRET')) define('STRIPE_WEBHOOK_SECRET', '');

// Google Maps Configuration
if (!defined('GOOGLE_MAPS_API_KEY')) define('GOOGLE_MAPS_API_KEY', '');

// Paths
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
if (!defined('APP_PATH')) define('APP_PATH', ROOT_PATH . '/app');
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', ROOT_PATH . '/public');
if (!defined('STORAGE_PATH')) define('STORAGE_PATH', ROOT_PATH . '/storage');
if (!defined('UPLOAD_PATH')) define('UPLOAD_PATH', STORAGE_PATH . '/uploads');

// Cron / scheduled jobs: shared secret for the HTTP cron endpoint (empty = CLI only)
if (!defined('CRON_SECRET')) define('CRON_SECRET', '');

// Timezone
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'UTC');
date_default_timezone_set(APP_TIMEZONE);

// Error Reporting
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

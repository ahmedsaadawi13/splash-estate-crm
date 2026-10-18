<?php
// FILE: /app/controllers/HealthController.php

/**
 * SplashEstate CRM - Health Check Controller
 *
 * Public endpoints (no sensitive details):
 *   GET /health          basic status (200 healthy / 503 when the database is down)
 *   GET /health/alive    liveness (process is up)
 *   GET /health/ready    readiness (database reachable, storage writable)
 *
 * Protected endpoint:
 *   GET /health/detailed full diagnostics. Requires a logged-in platform admin,
 *   or the HEALTH_TOKEN constant (from .env) sent as "Authorization: Bearer <token>"
 *   or "X-Health-Token: <token>".
 */

class HealthController extends Controller {

    /**
     * Basic health check
     */
    public function index() {
        $this->noCache();
        $db = $this->checkDatabase();
        $healthy = $db['healthy'];

        $this->jsonResponse(array(
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'timestamp' => time()
        ), $healthy ? 200 : 503);
    }

    /**
     * Detailed diagnostics (platform admin or HEALTH_TOKEN only)
     */
    public function detailed() {
        $this->noCache();
        if (!$this->isAuthorizedForDetails()) {
            $this->jsonResponse(array('status' => 'forbidden', 'message' => 'Detailed health information requires authorization'), 403);
        }

        $checks = array(
            'database' => $this->checkDatabase(true),
            'filesystem' => $this->checkFilesystem(true),
            'configuration' => $this->checkConfiguration(),
            'php' => $this->checkPHP(),
            'migrations' => $this->checkMigrations(),
            'external_services' => $this->checkExternalServices()
        );

        $status = 'healthy';
        if (!$checks['database']['healthy']) {
            $status = 'unhealthy';
        } elseif (!$checks['filesystem']['healthy'] || !$checks['configuration']['healthy'] || !$checks['php']['healthy'] || !$checks['migrations']['healthy']) {
            $status = 'degraded';
        }

        $this->jsonResponse(array(
            'status' => $status,
            'timestamp' => time(),
            'checks' => $checks,
            'system' => array(
                'php_version' => PHP_VERSION,
                'environment' => defined('APP_ENV') ? APP_ENV : 'unknown',
                'debug' => defined('APP_DEBUG') && APP_DEBUG ? true : false,
                'timezone' => date_default_timezone_get()
            )
        ), $status === 'unhealthy' ? 503 : 200);
    }

    /**
     * Readiness check
     */
    public function ready() {
        $this->noCache();
        $db = $this->checkDatabase();
        $fs = $this->checkFilesystem();
        $ready = $db['healthy'] && $fs['healthy'];

        $this->jsonResponse(array(
            'ready' => $ready,
            'timestamp' => time(),
            'checks' => array('database' => $db['healthy'], 'filesystem' => $fs['healthy'])
        ), $ready ? 200 : 503);
    }

    /**
     * Liveness check
     */
    public function alive() {
        $this->noCache();
        $this->jsonResponse(array('alive' => true, 'timestamp' => time()));
    }

    /**
     * Platform admin session, or a matching HEALTH_TOKEN
     */
    private function isAuthorizedForDetails() {
        if ($this->getUserRole() === 'platform_admin') {
            return true;
        }
        if (!defined('HEALTH_TOKEN') || !is_string(HEALTH_TOKEN) || strlen(HEALTH_TOKEN) < 16) {
            return false;
        }
        $provided = '';
        if (!empty($_SERVER['HTTP_X_HEALTH_TOKEN'])) {
            $provided = (string)$_SERVER['HTTP_X_HEALTH_TOKEN'];
        } elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
            $provided = trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
        }
        return $provided !== '' && hash_equals(HEALTH_TOKEN, $provided);
    }

    /**
     * Database connectivity
     */
    private function checkDatabase($withDetails = false) {
        try {
            $conn = Database::getInstance()->getConnection();
            $started = microtime(true);
            $ok = (int)$conn->query('SELECT 1')->fetchColumn() === 1;
            $result = array(
                'healthy' => $ok,
                'message' => $ok ? 'Database connection successful' : 'Database query failed'
            );
            if ($withDetails) {
                $result['latency_ms'] = round((microtime(true) - $started) * 1000, 2);
                $result['server_version'] = (string)$conn->getAttribute(PDO::ATTR_SERVER_VERSION);
            }
            return $result;
        } catch (Throwable $e) {
            error_log('Health check: database error: ' . $e->getMessage());
            return array('healthy' => false, 'message' => 'Database connection failed');
        }
    }

    /**
     * Writable storage directories and disk space
     */
    private function checkFilesystem($withDetails = false) {
        $details = array();
        $healthy = true;

        foreach (array('storage' => STORAGE_PATH, 'uploads' => UPLOAD_PATH, 'logs' => STORAGE_PATH . '/logs') as $name => $path) {
            $writable = is_dir($path) && is_writable($path);
            $details[$name] = $writable ? 'writable' : 'not writable or missing';
            if (!$writable && $name !== 'logs') {
                $healthy = false;
            }
        }

        $diskFree = @disk_free_space(ROOT_PATH);
        $diskTotal = @disk_total_space(ROOT_PATH);
        if ($diskFree && $diskTotal) {
            $usedPercent = (($diskTotal - $diskFree) / $diskTotal) * 100;
            $details['disk_space'] = array(
                'free' => $this->formatBytes($diskFree),
                'total' => $this->formatBytes($diskTotal),
                'used_percent' => round($usedPercent, 2)
            );
            if ($usedPercent > 95) {
                $healthy = false;
            }
        }

        $result = array(
            'healthy' => $healthy,
            'message' => $healthy ? 'Filesystem checks passed' : 'Some filesystem issues detected'
        );
        if ($withDetails) {
            $result['details'] = $details;
        }
        return $result;
    }

    /**
     * Critical configuration (reports problems, never values)
     */
    private function checkConfiguration() {
        $issues = array();
        $warnings = array();

        if (!file_exists(ROOT_PATH . '/.env')) {
            $warnings[] = '.env file missing (using defaults)';
        }
        foreach (array('DB_HOST', 'DB_NAME', 'DB_USER', 'BASE_URL') as $constant) {
            if (!defined($constant) || constant($constant) === '' || constant($constant) === null) {
                $issues[] = $constant . ' not configured';
            }
        }
        $env = defined('APP_ENV') ? APP_ENV : '';
        if ($env === 'production') {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $issues[] = 'APP_DEBUG must be false in production';
            }
            if (defined('BASE_URL') && stripos(BASE_URL, 'https://') !== 0) {
                $warnings[] = 'BASE_URL should use https in production';
            }
            if (defined('DB_USER') && DB_USER === 'root') {
                $warnings[] = 'Database user should not be root in production';
            }
        }

        return array(
            'healthy' => empty($issues),
            'message' => empty($issues) ? 'Configuration valid' : 'Configuration issues found',
            'issues' => $issues,
            'warnings' => $warnings
        );
    }

    /**
     * PHP version and extensions
     */
    private function checkPHP() {
        $required = array('pdo', 'pdo_mysql', 'mbstring', 'curl', 'json', 'openssl');
        $missing = array();
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        return array(
            'healthy' => empty($missing) && version_compare(PHP_VERSION, '7.0.0', '>='),
            'message' => empty($missing) ? 'All required extensions loaded' : 'Missing PHP extensions',
            'details' => array(
                'missing_extensions' => $missing,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize')
            )
        );
    }

    /**
     * Pending database migrations
     */
    private function checkMigrations() {
        $dir = ROOT_PATH . '/database/migrations';
        $files = is_dir($dir) ? glob($dir . '/[0-9][0-9][0-9][0-9]_[0-9][0-9]_[0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9]_*.php') : array();
        $names = array_map('basename', $files ?: array());

        try {
            $conn = Database::getInstance()->getConnection();
            $exists = $conn->query("SHOW TABLES LIKE 'migrations'")->fetchColumn();
            $executed = $exists ? $conn->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN) : array();
        } catch (Throwable $e) {
            return array('healthy' => false, 'message' => 'Could not read migration status');
        }

        $pending = array_values(array_diff($names, $executed));
        return array(
            'healthy' => empty($pending),
            'message' => empty($pending) ? 'Database schema is up to date' : count($pending) . ' pending migration(s): run php migrate.php migrate',
            'pending' => $pending
        );
    }

    /**
     * Which integrations are configured (never their credentials or hosts)
     */
    private function checkExternalServices() {
        $configured = function ($constant) {
            return defined($constant) && constant($constant) !== '' && constant($constant) !== null && constant($constant) !== false;
        };

        return array(
            'healthy' => true,
            'message' => 'External services check completed',
            'details' => array(
                'stripe' => $configured('STRIPE_SECRET_KEY') ? 'configured' : 'not configured (demo mode)',
                'stripe_webhook' => $configured('STRIPE_WEBHOOK_SECRET') ? 'configured' : 'not configured',
                'google_maps' => $configured('GOOGLE_MAPS_API_KEY') ? 'configured' : 'not configured',
                'mail_driver' => defined('MAIL_DRIVER') ? (string)MAIL_DRIVER : 'log',
                'smtp' => ($configured('MAIL_HOST') && $configured('MAIL_USERNAME')) ? 'configured' : 'not configured'
            )
        );
    }

    private function noCache() {
        header('Cache-Control: no-store, max-age=0');
    }

    /**
     * Format bytes to a human-readable size
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = (int)min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

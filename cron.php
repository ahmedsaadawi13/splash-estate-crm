<?php
/**
 * SplashEstate CRM - Scheduled jobs (cron entry point)
 *
 * Runs background work:
 *   workflow_actions     delayed workflow actions whose time has come
 *   scheduled_workflows  workflows with the "Scheduled" trigger (hourly/daily/weekly)
 *   webhook_retries      failed webhook deliveries (WebhookService::retryFailed)
 *   task_reminders       one in-app reminder per task due in the next 24 hours
 *   cleanup              expired password resets, login attempts older than 30 days
 *
 * Usage (CLI, recommended) - add to the web server user's crontab:
 *   * * * * * php /path/to/SplashEstateCRM/cron.php >> /path/to/SplashEstateCRM/storage/logs/cron.log 2>&1
 *
 *   php cron.php                      run all jobs
 *   php cron.php task_reminders ...   run only the named jobs
 *   php cron.php --list               list jobs
 *
 * HTTP (only when CLI cron is unavailable): set CRON_SECRET in .env and call
 *   https://your-host/cron.php?key=<CRON_SECRET>
 * This file lives outside public/, so expose it only via a deliberate alias.
 * Without a CRON_SECRET, any non-CLI request is refused.
 *
 * Overlapping runs are prevented with a MySQL lock; a run that finds the lock
 * taken exits immediately.
 */

require_once __DIR__ . '/config/config.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
    if (!defined('CRON_SECRET') || CRON_SECRET === '' || $key === '' || !hash_equals((string)CRON_SECRET, $key)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    ignore_user_abort(true);
}
@set_time_limit(300);

require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Model.php';
require_once APP_PATH . '/core/View.php';
require_once APP_PATH . '/helpers/functions.php';
require_once APP_PATH . '/helpers/Scheduler.php';

$jobs = Scheduler::jobs();
$only = array();

if ($isCli) {
    $args = array_slice(isset($argv) ? $argv : array(), 1);
    if (in_array('--list', $args, true) || in_array('--help', $args, true) || in_array('-h', $args, true)) {
        echo "Jobs:\n";
        foreach ($jobs as $name => $description) {
            echo '  ' . str_pad($name, 22) . $description . "\n";
        }
        exit(0);
    }
    $only = $args;
} elseif (isset($_GET['jobs']) && is_string($_GET['jobs'])) {
    $only = array_filter(array_map('trim', explode(',', $_GET['jobs'])));
}

foreach ($only as $name) {
    if (!isset($jobs[$name])) {
        echo 'Unknown job: ' . $name . " (use --list)\n";
        exit(2);
    }
}

$started = microtime(true);
try {
    $scheduler = new Scheduler();
    $results = $scheduler->run(array_values($only));
} catch (Throwable $e) {
    error_log('cron.php failed: ' . $e->getMessage());
    echo '[' . date('Y-m-d H:i:s') . '] cron failed: ' . $e->getMessage() . "\n";
    exit(1);
}

$stamp = '[' . date('Y-m-d H:i:s') . ']';
if (isset($results['locked'])) {
    echo $stamp . " another cron run is in progress; skipped\n";
    exit(0);
}

$failed = false;
foreach ($results as $job => $outcome) {
    $summary = is_array($outcome['result'])
        ? implode(', ', array_map(function ($k, $v) { return $k . '=' . (is_scalar($v) ? $v : json_encode($v)); }, array_keys($outcome['result']), $outcome['result']))
        : (string)$outcome['result'];
    echo $stamp . ' ' . str_pad($job, 20) . ($outcome['ok'] ? 'ok    ' : 'FAILED') . ' ' . $summary . ' (' . $outcome['seconds'] . "s)\n";
    if (!$outcome['ok']) {
        $failed = true;
    }
}
echo $stamp . ' done in ' . round(microtime(true) - $started, 2) . "s\n";
exit($failed ? 1 : 0);

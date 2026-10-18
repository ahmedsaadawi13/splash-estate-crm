<?php
// FILE: /migrate.php

/**
 * SplashEstate CRM - Migration CLI Tool
 *
 * Fresh install:  mysql -u USER -p DBNAME < database.sql   (current full schema + demo data)
 *                 php migrate.php migrate                    (records the migrations as applied;
 *                                                             they are idempotent no-ops on a fresh schema)
 * Upgrade:        php migrate.php migrate                    (brings an older install up to database.sql)
 *
 * Usage:
 *   php migrate.php migrate              Run pending migrations
 *   php migrate.php rollback             Roll back the last batch
 *   php migrate.php status               Show migration status
 *   php migrate.php create <name>        Create a new migration (snake_case name)
 *
 * Options:
 *   --database=<name>                    Use another database than DB_NAME (e.g. a staging copy)
 *   --no-color                           Disable ANSI colours
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args = array();
$options = array();
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') === 0) {
        $pair = explode('=', substr($arg, 2), 2);
        $options[$pair[0]] = isset($pair[1]) ? $pair[1] : true;
    } else {
        $args[] = $arg;
    }
}

if (isset($options['database'])) {
    if (!is_string($options['database']) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $options['database'])) {
        fwrite(STDERR, "Invalid --database name\n");
        exit(2);
    }
    // config.php only defines constants that are not defined yet
    define('DB_NAME', $options['database']);
}

// Throw on connection errors instead of printing an HTML error page
if (!defined('TEST_MODE')) {
    define('TEST_MODE', true);
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/core/Database.php';
require_once __DIR__ . '/app/core/Migration.php';
require_once __DIR__ . '/app/core/MigrationRunner.php';

$useColor = !isset($options['no-color']) && function_exists('posix_isatty') && @posix_isatty(STDOUT);

function colorize($text, $color) {
    global $useColor;
    $colors = array('green' => "\033[32m", 'red' => "\033[31m", 'yellow' => "\033[33m", 'blue' => "\033[34m");
    return ($useColor && isset($colors[$color])) ? $colors[$color] . $text . "\033[0m" : $text;
}

function success($message) {
    echo colorize('✓ ', 'green') . $message . PHP_EOL;
}

function error($message) {
    fwrite(STDERR, colorize('✗ ', 'red') . $message . PHP_EOL);
}

function info($message) {
    echo colorize('ℹ ', 'blue') . $message . PHP_EOL;
}

function warning($message) {
    echo colorize('⚠ ', 'yellow') . $message . PHP_EOL;
}

function printHelp() {
    echo PHP_EOL . colorize('SplashEstate CRM - Migration Tool', 'blue') . PHP_EOL . PHP_EOL;
    echo "Usage:" . PHP_EOL;
    echo "  php migrate.php <command> [arguments] [--database=<name>] [--no-color]" . PHP_EOL . PHP_EOL;
    echo "Commands:" . PHP_EOL;
    echo "  migrate              Run all pending migrations" . PHP_EOL;
    echo "  rollback             Roll back the last batch of migrations" . PHP_EOL;
    echo "  status               Show migration status" . PHP_EOL;
    echo "  create <name>        Create a new migration file (snake_case)" . PHP_EOL;
    echo "  help                 Show this help message" . PHP_EOL . PHP_EOL;
    echo "Fresh install: import database.sql, then run 'php migrate.php migrate' to record the baseline." . PHP_EOL;
    echo "Upgrade:       run 'php migrate.php migrate' (back up the database first)." . PHP_EOL . PHP_EOL;
}

$command = isset($args[0]) ? $args[0] : 'help';
if ($command === 'help' || !in_array($command, array('migrate', 'rollback', 'status', 'create'), true)) {
    printHelp();
    exit($command === 'help' ? 0 : 1);
}

try {
    $runner = new MigrationRunner();
} catch (Throwable $e) {
    error('Cannot connect to database ' . DB_NAME . ': ' . $e->getMessage());
    exit(1);
}

$exitCode = 0;

switch ($command) {
    case 'migrate':
        info('Running migrations on ' . DB_NAME . '...');
        $result = $runner->migrate();
        foreach ($result['executed'] as $migration) {
            success('Migrated: ' . $migration);
        }
        foreach ($result['failed'] as $failed) {
            error('Failed: ' . $failed['migration']);
            error('Error: ' . $failed['error']);
        }
        if ($result['success']) {
            empty($result['executed']) ? info($result['message']) : success($result['message']);
        } else {
            $exitCode = 1;
        }
        break;

    case 'rollback':
        info('Rolling back the last batch on ' . DB_NAME . '...');
        $result = $runner->rollback();
        foreach ($result['rolled_back'] as $migration) {
            success('Rolled back: ' . $migration);
        }
        foreach ($result['failed'] as $failed) {
            error('Failed: ' . $failed['migration']);
            error('Error: ' . $failed['error']);
        }
        if ($result['success']) {
            empty($result['rolled_back']) ? info($result['message']) : success($result['message']);
        } else {
            $exitCode = 1;
        }
        break;

    case 'status':
        $status = $runner->status();
        if (empty($status)) {
            warning('No migrations found in database/migrations');
            break;
        }
        printf("%-72s %-10s %s\n", 'Migration', 'Status', 'Batch');
        echo str_repeat('-', 90) . PHP_EOL;
        $pending = 0;
        foreach ($status as $item) {
            $label = ucfirst($item['status']);
            $padded = str_pad($label, 10);
            $colored = $item['status'] === 'executed' ? colorize($padded, 'green') : colorize($padded, $item['status'] === 'pending' ? 'yellow' : 'red');
            printf("%-72s %s %s\n", $item['migration'], $colored, $item['batch'] !== null ? $item['batch'] : '-');
            if ($item['status'] === 'pending') {
                $pending++;
            }
        }
        echo PHP_EOL;
        $pending > 0 ? warning($pending . ' pending migration(s)') : success('Database is up to date');
        break;

    case 'create':
        if (!isset($args[1])) {
            error('Migration name is required');
            echo 'Usage: php migrate.php create <migration_name>   e.g. add_avatar_to_users' . PHP_EOL;
            exit(1);
        }
        try {
            $filename = $runner->create($args[1]);
            success('Created migration: database/migrations/' . $filename);
        } catch (Exception $e) {
            error($e->getMessage());
            $exitCode = 1;
        }
        break;
}

exit($exitCode);

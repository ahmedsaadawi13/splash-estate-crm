<?php
// FILE: /tests/run_tests.php

/**
 * SplashEstate CRM - Test Runner
 *
 * Usage: php tests/run_tests.php [filter]
 *   filter  only run tests whose "group name" contains this text (case-insensitive)
 *
 * Every run recreates the test database (splashestate_crm_test) from
 * database.sql and applies the migrations, so tests never touch the
 * application database. Exit code: 0 = all passed, 1 = failures, 2 = setup error.
 */

require_once __DIR__ . '/bootstrap.php';

echo "=================================\n";
echo "SplashEstate CRM - Test Suite\n";
echo "=================================\n";
echo 'PHP ' . PHP_VERSION . ' | database ' . DB_NAME . "\n";

try {
    test_create_database_from_schema(DB_NAME);

    require_once APP_PATH . '/core/Migration.php';
    require_once APP_PATH . '/core/MigrationRunner.php';
    $runner = new MigrationRunner(test_pdo(DB_NAME));
    $result = $runner->migrate();
    if (!$result['success']) {
        throw new RuntimeException('Migrations failed on the fresh test schema: ' . json_encode($result['failed']));
    }
    echo "Test database created from database.sql (" . count($result['executed']) . " migration(s) recorded)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nSetup failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "The test database user needs CREATE/DROP privileges for " . DB_NAME . " and " . TEST_MIGRATION_DB . ".\n");
    exit(2);
}

$suite = new TestSuite();
foreach (glob(__DIR__ . '/cases/*Test.php') as $file) {
    require $file;
}

$filter = isset($argv[1]) ? $argv[1] : null;
$ok = $suite->run($filter);

exit($ok ? 0 : 1);

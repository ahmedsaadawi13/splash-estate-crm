<?php
// FILE: /tests/cases/MigrationTest.php
// Migration runner end to end on a scratch database (splashestate_crm_migtest).

require_once APP_PATH . '/core/Migration.php';
require_once APP_PATH . '/core/MigrationRunner.php';

function test_column_exists(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $stmt->execute(array(':t' => $table, ':c' => $column));
    return (int)$stmt->fetchColumn() > 0;
}

function test_table_exists(PDO $pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
    $stmt->execute(array(':t' => $table));
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Scratch database that looks like a pre-October install: current schema
 * minus everything the October migration adds.
 */
function test_pre_october_database() {
    $pdo = test_create_database_from_schema(TEST_MIGRATION_DB);
    $pdo->exec("DROP TABLE notifications, password_resets, login_attempts, lead_score_history, lead_scores, lead_scoring_rules");
    $pdo->exec("ALTER TABLE leads DROP COLUMN tags");
    $pdo->exec("ALTER TABLE workflows DROP COLUMN last_run_at");
    $pdo->exec("ALTER TABLE workflow_action_logs DROP INDEX idx_due, DROP COLUMN scheduled_for, DROP COLUMN started_at, DROP COLUMN completed_at,
                MODIFY COLUMN status enum('pending','completed','failed','skipped') DEFAULT 'pending'");
    $pdo->exec("DELETE FROM permissions WHERE slug IN ('workflows.manage', 'scoring.manage', 'audit.view')");
    $pdo->exec("DELETE FROM user_roles");
    return $pdo;
}

$suite->add('Migrations', 'migrate brings a pre-October install up to database.sql', function () {
    $pdo = test_pre_october_database();
    $runner = new MigrationRunner($pdo);

    $pending = $runner->getPendingMigrations();
    assert_contains('2026_10_18_000001_add_automation_and_security_tables.php', $pending);

    $result = $runner->migrate();
    assert_true($result['success'], 'migrate: ' . json_encode($result['failed']));
    assert_equals($pending, $result['executed']);

    foreach (array('notifications', 'password_resets', 'login_attempts', 'lead_scoring_rules', 'lead_scores', 'lead_score_history', 'stripe_events') as $table) {
        assert_true(test_table_exists($pdo, $table), $table . ' created');
    }
    assert_true(test_column_exists($pdo, 'leads', 'tags'));
    assert_true(test_column_exists($pdo, 'workflows', 'last_run_at'));
    assert_true(test_column_exists($pdo, 'workflow_action_logs', 'scheduled_for'));
    $type = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_action_logs' AND COLUMN_NAME = 'status'")->fetchColumn();
    assert_contains("'scheduled'", $type);

    $grants = $pdo->query("SELECT CONCAT(r.slug, ':', p.slug) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE p.slug IN ('workflows.manage', 'scoring.manage', 'audit.view')")->fetchAll(PDO::FETCH_COLUMN);
    sort($grants);
    assert_equals(array('sales_manager:scoring.manage', 'sales_manager:workflows.manage', 'tenant_admin:audit.view', 'tenant_admin:scoring.manage', 'tenant_admin:workflows.manage'), $grants);

    $missingRoles = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE u.role IN ('tenant_admin', 'agent') AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id)")->fetchColumn();
    assert_equals(0, $missingRoles, 'user_roles seeded from users.role');

    $again = $runner->migrate();
    assert_true($again['success']);
    assert_equals(array(), $again['executed'], 'nothing pending on second run');
});

$suite->add('Migrations', 'migrations are no-ops on a fresh database.sql schema', function () {
    $pdo = test_create_database_from_schema(TEST_MIGRATION_DB);
    $columnsBefore = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME NOT IN ('migrations', 'stripe_events')")->fetchColumn();
    $grantsBefore = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn();

    $result = (new MigrationRunner($pdo))->migrate();
    assert_true($result['success'], json_encode($result['failed']));

    $columnsAfter = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME NOT IN ('migrations', 'stripe_events')")->fetchColumn();
    assert_equals($columnsBefore, $columnsAfter, 'no schema changes');
    assert_equals($grantsBefore, (int)$pdo->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn(), 'no permission changes');
});

$suite->add('Migrations', 'rollback reverts the last batch; status reports it', function () {
    $pdo = test_pre_october_database();
    $runner = new MigrationRunner($pdo);
    assert_true($runner->migrate()['success']);

    $result = $runner->rollback();
    assert_true($result['success'], json_encode($result['failed']));
    assert_equals('2026_10_18_000002_create_stripe_events_table.php', $result['rolled_back'][0], 'newest first');
    assert_false(test_table_exists($pdo, 'notifications'));
    assert_false(test_table_exists($pdo, 'stripe_events'));
    assert_false(test_column_exists($pdo, 'leads', 'tags'));
    assert_false(test_column_exists($pdo, 'workflow_action_logs', 'scheduled_for'));
    assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE slug = 'audit.view'")->fetchColumn());

    foreach ($runner->status() as $row) {
        assert_equals('pending', $row['status'], $row['migration']);
    }
    assert_true($runner->migrate()['success'], 're-apply after rollback');
});

$suite->add('Migrations', 'create() writes a runnable migration; failures are not recorded', function () {
    $pdo = test_create_database_from_schema(TEST_MIGRATION_DB);
    $dir = TEST_TMP_DIR . '/migrations_' . bin2hex(random_bytes(3));
    mkdir($dir, 0777, true);
    $runner = new MigrationRunner($pdo, $dir);

    assert_throws('InvalidArgumentException', function () use ($runner) {
        $runner->create('Bad Name!');
    });
    $file = $runner->create('add_test_widgets');
    assert_true((bool)preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_add_test_widgets\.php$/', $file));
    assert_throws('RuntimeException', function () use ($runner) {
        $runner->create('add_test_widgets');
    }, 'duplicate class name');

    // Make it do something, plus a broken migration after it
    $code = file_get_contents($dir . '/' . $file);
    $code = str_replace("    public function up() {\n", "    public function up() {\n        \$this->createTable('test_widgets', '`id` int(11) NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)');\n", $code);
    $code = str_replace("    public function down() {\n", "    public function down() {\n        \$this->dropTable('test_widgets');\n", $code);
    file_put_contents($dir . '/' . $file, $code);
    file_put_contents($dir . '/2099_01_01_000000_broken_step.php', "<?php\nclass BrokenStep extends Migration {\n    public function up() { return \$this->execute('ALTER TABLE no_such_table ADD COLUMN x int'); }\n    public function down() { return true; }\n}\n");

    $result = $runner->migrate();
    assert_false($result['success']);
    assert_equals(array($file), $result['executed']);
    assert_equals('2099_01_01_000000_broken_step.php', $result['failed'][0]['migration']);
    assert_contains('no_such_table', $result['failed'][0]['error']);
    assert_true(test_table_exists($pdo, 'test_widgets'));

    $status = array();
    foreach ($runner->status() as $row) {
        $status[$row['migration']] = $row['status'];
    }
    assert_equals('executed', $status[$file]);
    assert_equals('pending', $status['2099_01_01_000000_broken_step.php'], 'failed migration not recorded');

    unlink($dir . '/2099_01_01_000000_broken_step.php');
    assert_true($runner->rollback()['success']);
    assert_false(test_table_exists($pdo, 'test_widgets'));
});

$suite->add('Migrations', 'migrate.php CLI exits non-zero on bad input', function () {
    $php = escapeshellarg(PHP_BINARY);
    $script = escapeshellarg(ROOT_PATH . '/migrate.php');
    exec($php . ' ' . $script . ' bogus-command 2>&1', $output, $code);
    assert_equals(1, $code);
    exec($php . ' ' . $script . ' status --database=' . escapeshellarg('x;drop') . ' 2>&1', $output, $code);
    assert_equals(2, $code);
    $output = array();
    exec($php . ' ' . $script . ' status --no-color --database=' . TEST_MIGRATION_DB . ' 2>&1', $output, $code);
    assert_equals(0, $code, implode("\n", $output));
    assert_contains('2026_10_18_000001_add_automation_and_security_tables.php', implode("\n", $output));
});

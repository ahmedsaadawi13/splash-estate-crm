<?php
// FILE: /tests/bootstrap.php

/**
 * SplashEstate CRM - Test Bootstrap
 *
 * - Uses the dedicated database splashestate_crm_test (recreated from
 *   database.sql on every run; DB_HOST/DB_USER/DB_PASS come from .env).
 *   Override the name with SPLASH_TEST_DB=<name> (must end in "_test").
 * - Writes logs/uploads to a temporary STORAGE_PATH, never to storage/.
 * - Provides a tiny assertion framework (TestSuite) and helpers to start
 *   throw-away PHP servers (webhook receiver, fake SMTP, app server).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('TEST_MODE', true);

$testDb = getenv('SPLASH_TEST_DB') ?: 'splashestate_crm_test';
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb)) {
    fwrite(STDERR, "Refusing to use database '{$testDb}': test database names must end in _test\n");
    exit(2);
}
define('DB_NAME', $testDb);
define('TEST_MIGRATION_DB', preg_replace('/_test$/', '_migtest', $testDb));

// Webhook tests deliver to local endpoints, which is only allowed in development
define('APP_ENV', 'development');
define('APP_DEBUG', true);
define('MAIL_DRIVER', 'log');

define('TEST_TMP_DIR', rtrim(sys_get_temp_dir(), '/') . '/splashestate_tests_' . getmypid());
define('STORAGE_PATH', TEST_TMP_DIR . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
@mkdir(UPLOAD_PATH, 0777, true);
@mkdir(STORAGE_PATH . '/logs', 0777, true);

// .env supplies DB_HOST/DB_USER/DB_PASS/BASE_URL; constants above take precedence
require_once dirname(__DIR__) . '/config/config.php';

require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Model.php';
require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/View.php';
require_once APP_PATH . '/helpers/functions.php';

register_shutdown_function(function () {
    TestServer::stopAll();
    TestSuite::removeDir(TEST_TMP_DIR);
});

/**
 * Open a PDO connection to the MySQL server (optionally without a database)
 */
function test_pdo($database = null) {
    $dsn = 'mysql:host=' . DB_HOST . ($database !== null ? ';dbname=' . $database : '') . ';charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ));
}

/**
 * Drop and recreate a database from database.sql (the CREATE DATABASE/USE
 * lines of the dump are ignored so the target name is respected)
 * @param string $database Database name (must end in _test or _migtest)
 */
function test_create_database_from_schema($database) {
    if (!preg_match('/^[A-Za-z0-9_]+_(test|migtest)$/', $database)) {
        throw new RuntimeException('Refusing to recreate non-test database ' . $database);
    }
    $server = test_pdo();
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = test_pdo($database);
    foreach (test_split_sql(file_get_contents(ROOT_PATH . '/database.sql')) as $statement) {
        $pdo->exec($statement);
    }
    return $pdo;
}

/**
 * Split an SQL dump into statements (handles quotes and -- comments)
 * @param string $sql
 * @return array
 */
function test_split_sql($sql) {
    $statements = array();
    $current = '';
    $quote = null;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote === null && $char === '-' && substr($sql, $i, 3) === '-- ') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $length : $end;
            $current .= "\n";
            continue;
        }
        $current .= $char;
        if ($quote !== null) {
            if ($char === '\\') {
                $current .= $sql[++$i];
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
        } elseif ($char === ';') {
            $statements[] = trim(substr($current, 0, -1));
            $current = '';
        }
    }
    $statements[] = trim($current);

    return array_values(array_filter($statements, function ($s) {
        return $s !== '' && !preg_match('/^(CREATE DATABASE|USE)\b/i', $s);
    }));
}

/**
 * Minimal test framework
 */
class TestSuite {

    private $tests = array();
    private $passed = 0;
    private $failed = 0;
    private $skipped = 0;
    private $failures = array();
    private $assertions = 0;

    /** @var TestSuite */
    public static $current;

    /**
     * Register a test
     * @param string $group Group name
     * @param string $name Test name
     * @param callable $fn Test body (throw TestSkipped to skip)
     * @param string $note Optional note, e.g. "depends on LeadsController"
     */
    public function add($group, $name, $fn, $note = '') {
        $this->tests[] = array($group, $name, $fn, $note);
    }

    /**
     * Run every registered test (optionally only groups matching $filter)
     * @return bool True if no test failed
     */
    public function run($filter = null) {
        self::$current = $this;
        $group = null;
        $started = microtime(true);

        foreach ($this->tests as $test) {
            list($testGroup, $name, $fn, $note) = $test;
            if ($filter !== null && stripos($testGroup . ' ' . $name, $filter) === false) {
                continue;
            }
            if ($testGroup !== $group) {
                $group = $testGroup;
                echo "\n--- {$group} ---\n";
            }

            $label = $name . ($note !== '' ? " [{$note}]" : '');
            set_error_handler(function ($severity, $message, $file, $line) {
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                throw new ErrorException($message, 0, $severity, $file, $line);
            });
            try {
                $fn();
                $this->passed++;
                echo "  ✓ {$label}\n";
            } catch (TestSkipped $e) {
                $this->skipped++;
                echo "  ⊘ {$label} (skipped: {$e->getMessage()})\n";
            } catch (Throwable $e) {
                $this->failed++;
                $where = basename($e->getFile()) . ':' . $e->getLine();
                $this->failures[] = "{$group} / {$label}: " . $e->getMessage() . " ({$where})";
                echo "  ✗ {$label}\n      " . get_class($e) . ': ' . $e->getMessage() . " ({$where})\n";
            }
            restore_error_handler();
        }

        $seconds = round(microtime(true) - $started, 1);
        echo "\n=================================\n";
        echo "Passed:  {$this->passed}\nFailed:  {$this->failed}\nSkipped: {$this->skipped}\n";
        echo "Assertions: {$this->assertions}   Time: {$seconds}s\n";
        echo "=================================\n";
        if ($this->failed > 0) {
            echo "\nFailures:\n";
            foreach ($this->failures as $failure) {
                echo "  - {$failure}\n";
            }
        }
        return $this->failed === 0;
    }

    public static function countAssertion() {
        if (self::$current) {
            self::$current->assertions++;
        }
    }

    public static function removeDir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

class TestSkipped extends Exception {}
class AssertionFailed extends Exception {}

function skip_test($reason) {
    throw new TestSkipped($reason);
}

function assert_true($condition, $message = 'Expected true') {
    TestSuite::countAssertion();
    if ($condition !== true) {
        throw new AssertionFailed($message);
    }
}

function assert_false($condition, $message = 'Expected false') {
    TestSuite::countAssertion();
    if ($condition !== false) {
        throw new AssertionFailed($message . ' (got ' . var_export($condition, true) . ')');
    }
}

function assert_equals($expected, $actual, $message = '') {
    TestSuite::countAssertion();
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_contains($needle, $haystack, $message = '') {
    TestSuite::countAssertion();
    $found = is_array($haystack) ? in_array($needle, $haystack, true) : strpos((string)$haystack, (string)$needle) !== false;
    if (!$found) {
        $shown = is_array($haystack) ? json_encode($haystack) : substr((string)$haystack, 0, 300);
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . var_export($needle, true) . ' not found in ' . $shown);
    }
}

function assert_not_contains($needle, $haystack, $message = '') {
    TestSuite::countAssertion();
    $found = is_array($haystack) ? in_array($needle, $haystack, true) : strpos((string)$haystack, (string)$needle) !== false;
    if ($found) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . var_export($needle, true) . ' unexpectedly found');
    }
}

function assert_throws($exceptionClass, $fn, $message = '') {
    TestSuite::countAssertion();
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $exceptionClass) {
            return $e;
        }
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . $exceptionClass . ', got ' . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . $exceptionClass . ' to be thrown');
}

/**
 * Whether a PHP file can be loaded in a separate process (catches compile-time
 * fatals such as incompatible method signatures without killing the runner)
 * @param string $file Path relative to the project root
 * @return bool
 */
function class_file_loads($file) {
    static $cache = array();
    if (isset($cache[$file])) {
        return $cache[$file];
    }
    $root = ROOT_PATH;
    $code = 'define("TEST_MODE", true); $_SERVER["REQUEST_METHOD"] = "GET"; require ' . var_export($root . '/config/config.php', true) . ';'
        . ' foreach (array("Database", "Controller", "Model", "View") as $c) { require_once ' . var_export($root . '/app/core/', true) . ' . $c . ".php"; }'
        . ' require_once ' . var_export($root . '/app/helpers/functions.php', true) . '; require_once ' . var_export($root . '/' . $file, true) . '; echo "OK";';
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
    return $cache[$file] = (trim((string)$output) === 'OK');
}

/**
 * Background PHP processes used by tests (built-in web server, fake SMTP server)
 */
class TestServer {

    private static $processes = array();

    /**
     * Start "php -S" on a free port
     * @param string $docroot Document root
     * @param string|null $router Router script
     * @return int Port
     */
    public static function startPhpServer($docroot, $router = null) {
        $port = self::freePort();
        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docroot) . ($router !== null ? ' ' . escapeshellarg($router) : '');
        self::spawn($cmd, $port);
        return $port;
    }

    /**
     * Start a PHP script that listens on a port it receives as first argument
     * @param string $script Script path
     * @param array $args Extra arguments
     * @return int Port
     */
    public static function startScript($script, $args = array()) {
        $port = self::freePort();
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $port;
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        self::spawn($cmd, $port);
        return $port;
    }

    private static function spawn($cmd, $port) {
        $log = TEST_TMP_DIR . '/server_' . $port . '.log';
        $process = proc_open('exec ' . $cmd, array(0 => array('pipe', 'r'), 1 => array('file', $log, 'a'), 2 => array('file', $log, 'a')), $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start: ' . $cmd);
        }
        self::$processes[] = $process;
        // Wait until the port accepts connections
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Server did not start on port ' . $port . ': ' . @file_get_contents($log));
    }

    public static function stopAll() {
        foreach (self::$processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        self::$processes = array();
    }

    private static function freePort() {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int)substr(strrchr($name, ':'), 1);
    }
}

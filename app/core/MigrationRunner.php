<?php
// FILE: /app/core/MigrationRunner.php

/**
 * SplashEstate CRM - Migration Runner
 * Applies, rolls back and reports database migrations (see migrate.php).
 *
 * Executed migrations are tracked in the `migrations` table with a batch
 * number; rollback() reverts the most recent batch. A MySQL advisory lock
 * prevents two deploys from migrating the same database concurrently.
 */

class MigrationRunner {

    const FILE_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/';

    /** @var PDO */
    private $conn;
    private $migrationsPath;
    private $migrationsTable = 'migrations';
    private $lockName;

    /**
     * @param PDO|null $conn Connection (defaults to the application database)
     * @param string|null $migrationsPath Directory with migration files
     */
    public function __construct($conn = null, $migrationsPath = null) {
        $this->conn = $conn instanceof PDO ? $conn : Database::getInstance()->getConnection();
        $this->migrationsPath = $migrationsPath !== null ? rtrim($migrationsPath, '/') : ROOT_PATH . '/database/migrations';
        $this->lockName = 'splashestate_migrations_' . substr(sha1((string)$this->conn->query('SELECT DATABASE()')->fetchColumn()), 0, 16);

        $this->ensureMigrationsTable();
    }

    /**
     * Ensure migrations tracking table exists
     */
    private function ensureMigrationsTable() {
        $this->conn->exec("CREATE TABLE IF NOT EXISTS `{$this->migrationsTable}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `migration` varchar(255) NOT NULL,
            `batch` int(11) NOT NULL,
            `executed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Run pending migrations (stops at the first failure)
     * @return array success, executed, failed, message
     */
    public function migrate() {
        if (!$this->acquireLock()) {
            return array('success' => false, 'executed' => array(), 'failed' => array(array('migration' => '-', 'error' => 'Another migration process is running')), 'message' => 'Could not acquire migration lock');
        }

        try {
            $pending = $this->getPendingMigrations();
            if (empty($pending)) {
                return array('success' => true, 'executed' => array(), 'failed' => array(), 'message' => 'No pending migrations');
            }

            $batch = $this->getNextBatchNumber();
            $executed = array();
            $failed = array();

            foreach ($pending as $migrationFile) {
                $result = $this->runMigration($migrationFile, $batch);
                if ($result['success']) {
                    $executed[] = $migrationFile;
                } else {
                    $failed[] = array('migration' => $migrationFile, 'error' => $result['error']);
                    break;
                }
            }

            return array(
                'success' => empty($failed),
                'executed' => $executed,
                'failed' => $failed,
                'message' => count($executed) . ' migration(s) executed' . (empty($failed) ? ' successfully' : ', then a migration failed')
            );
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Roll back the last batch (newest first, stops at the first failure)
     * @return array success, rolled_back, failed, message
     */
    public function rollback() {
        if (!$this->acquireLock()) {
            return array('success' => false, 'rolled_back' => array(), 'failed' => array(array('migration' => '-', 'error' => 'Another migration process is running')), 'message' => 'Could not acquire migration lock');
        }

        try {
            $lastBatch = $this->getLastBatch();
            if (empty($lastBatch)) {
                return array('success' => true, 'rolled_back' => array(), 'failed' => array(), 'message' => 'No migrations to roll back');
            }

            $rolledBack = array();
            $failed = array();

            foreach (array_reverse($lastBatch) as $migration) {
                $result = $this->rollbackMigration($migration['migration']);
                if ($result['success']) {
                    $rolledBack[] = $migration['migration'];
                } else {
                    $failed[] = array('migration' => $migration['migration'], 'error' => $result['error']);
                    break;
                }
            }

            return array(
                'success' => empty($failed),
                'rolled_back' => $rolledBack,
                'failed' => $failed,
                'message' => count($rolledBack) . ' migration(s) rolled back'
            );
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Migration status (files on disk plus executed migrations whose file is gone)
     * @return array List of migration, status (executed|pending|missing), batch
     */
    public function status() {
        $executed = array();
        foreach ($this->getExecutedMigrations() as $row) {
            $executed[$row['migration']] = (int)$row['batch'];
        }

        $status = array();
        foreach ($this->getAllMigrationFiles() as $migration) {
            $status[] = array(
                'migration' => $migration,
                'status' => isset($executed[$migration]) ? 'executed' : 'pending',
                'batch' => isset($executed[$migration]) ? $executed[$migration] : null
            );
            unset($executed[$migration]);
        }
        foreach ($executed as $migration => $batch) {
            $status[] = array('migration' => $migration, 'status' => 'missing', 'batch' => $batch);
        }

        return $status;
    }

    /**
     * Pending migration file names
     * @return array
     */
    public function getPendingMigrations() {
        $executed = array_column($this->getExecutedMigrations(), 'migration');
        return array_values(array_diff($this->getAllMigrationFiles(), $executed));
    }

    /**
     * Create a new migration file from the template
     * @param string $name snake_case name, e.g. add_avatar_to_users
     * @return string Created file name
     * @throws InvalidArgumentException|RuntimeException
     */
    public function create($name) {
        $name = strtolower(trim((string)$name));
        if (!preg_match('/^[a-z][a-z0-9_]{2,100}$/', $name)) {
            throw new InvalidArgumentException('Migration name must be snake_case (letters, digits, underscores), e.g. add_avatar_to_users');
        }

        $filename = date('Y_m_d_His') . '_' . $name . '.php';
        $className = $this->getMigrationClass($filename);

        foreach ($this->getAllMigrationFiles() as $existing) {
            if ($this->getMigrationClass($existing) === $className) {
                throw new RuntimeException("A migration named {$name} already exists ({$existing})");
            }
        }

        $template = <<<PHP
<?php
// FILE: /database/migrations/{$filename}

/**
 * Migration: {$className}
 * Use the idempotent helpers (createTable, addColumn, addIndex, ...) so the
 * migration also works on databases created from database.sql.
 */

class {$className} extends Migration {

    public function up() {
        // \$this->createTable('example', '
        //     `id` int(11) NOT NULL AUTO_INCREMENT,
        //     `name` varchar(255) NOT NULL,
        //     `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        //     PRIMARY KEY (`id`)
        // ');
        return true;
    }

    public function down() {
        // \$this->dropTable('example');
        return true;
    }
}

PHP;

        if (!is_dir($this->migrationsPath) && !mkdir($this->migrationsPath, 0755, true) && !is_dir($this->migrationsPath)) {
            throw new RuntimeException('Cannot create ' . $this->migrationsPath);
        }
        $filepath = $this->migrationsPath . '/' . $filename;
        if (file_exists($filepath)) {
            throw new RuntimeException('File already exists: ' . $filename);
        }
        if (file_put_contents($filepath, $template) === false) {
            throw new RuntimeException('Cannot write ' . $filepath);
        }

        return $filename;
    }

    /**
     * Run a single migration
     */
    private function runMigration($migrationFile, $batch) {
        try {
            $migration = $this->instantiate($migrationFile);
            if ($migration->up() !== true) {
                return array('success' => false, 'error' => 'up() did not return true');
            }
            $this->recordMigration($migrationFile, $batch);
            return array('success' => true);
        } catch (Throwable $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Roll back a single migration
     */
    private function rollbackMigration($migrationFile) {
        try {
            $migration = $this->instantiate($migrationFile);
            if ($migration->down() !== true) {
                return array('success' => false, 'error' => 'down() did not return true');
            }
            $this->removeMigration($migrationFile);
            return array('success' => true);
        } catch (Throwable $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Load a migration file and create its instance
     * @return Migration
     */
    private function instantiate($migrationFile) {
        if (!preg_match(self::FILE_PATTERN, $migrationFile)) {
            throw new RuntimeException('Invalid migration file name: ' . $migrationFile);
        }
        $migrationPath = $this->migrationsPath . '/' . $migrationFile;
        if (!is_file($migrationPath)) {
            throw new RuntimeException('Migration file not found: ' . $migrationFile);
        }

        require_once $migrationPath;

        $migrationClass = $this->getMigrationClass($migrationFile);
        if (!class_exists($migrationClass, false) || !is_subclass_of($migrationClass, 'Migration')) {
            throw new RuntimeException('Migration class ' . $migrationClass . ' not found in ' . $migrationFile);
        }

        return new $migrationClass($this->conn);
    }

    /**
     * All migration files, oldest first
     */
    private function getAllMigrationFiles() {
        if (!is_dir($this->migrationsPath)) {
            return array();
        }
        $migrations = array();
        foreach (scandir($this->migrationsPath) as $file) {
            if (preg_match(self::FILE_PATTERN, $file)) {
                $migrations[] = $file;
            }
        }
        sort($migrations, SORT_STRING);
        return $migrations;
    }

    private function getExecutedMigrations() {
        return $this->conn->query("SELECT migration, batch FROM `{$this->migrationsTable}` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getLastBatch() {
        $batch = (int)$this->conn->query("SELECT COALESCE(MAX(batch), 0) FROM `{$this->migrationsTable}`")->fetchColumn();
        if ($batch === 0) {
            return array();
        }
        $stmt = $this->conn->prepare("SELECT migration FROM `{$this->migrationsTable}` WHERE batch = :batch ORDER BY id ASC");
        $stmt->execute(array(':batch' => $batch));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getNextBatchNumber() {
        return (int)$this->conn->query("SELECT COALESCE(MAX(batch), 0) FROM `{$this->migrationsTable}`")->fetchColumn() + 1;
    }

    private function recordMigration($migration, $batch) {
        $stmt = $this->conn->prepare("INSERT INTO `{$this->migrationsTable}` (migration, batch) VALUES (:migration, :batch)");
        $stmt->execute(array(':migration' => $migration, ':batch' => (int)$batch));
    }

    private function removeMigration($migration) {
        $stmt = $this->conn->prepare("DELETE FROM `{$this->migrationsTable}` WHERE migration = :migration");
        $stmt->execute(array(':migration' => $migration));
    }

    /**
     * Class name from file name: 2025_01_15_120000_create_users_table.php -> CreateUsersTable
     */
    private function getMigrationClass($filename) {
        $parts = explode('_', preg_replace('/\.php$/', '', $filename));
        array_splice($parts, 0, 4);
        return implode('', array_map('ucfirst', $parts));
    }

    private function acquireLock() {
        $stmt = $this->conn->prepare('SELECT GET_LOCK(:name, 10)');
        $stmt->execute(array(':name' => $this->lockName));
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseLock() {
        $stmt = $this->conn->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute(array(':name' => $this->lockName));
    }
}

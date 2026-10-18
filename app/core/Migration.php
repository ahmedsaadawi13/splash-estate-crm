<?php
// FILE: /app/core/Migration.php

/**
 * SplashEstate CRM - Migration Base Class
 *
 * Migrations live in database/migrations/YYYY_MM_DD_HHMMSS_snake_case_name.php
 * and define a class named after the snake_case part (CamelCase) with up()
 * and down() methods returning true on success.
 *
 * Helpers are idempotent (creating an existing table/column/index is a no-op)
 * so migrations can safely run against databases created from database.sql.
 * execute() throws on SQL errors so a failed statement can never be recorded
 * as a successful migration.
 */

abstract class Migration {

    /** @var PDO */
    protected $conn;

    /**
     * @param PDO|null $conn Connection to migrate (defaults to the application database)
     */
    public function __construct($conn = null) {
        $this->conn = $conn instanceof PDO ? $conn : Database::getInstance()->getConnection();
    }

    /**
     * Apply the migration
     * @return bool Success
     */
    abstract public function up();

    /**
     * Reverse the migration
     * @return bool Success
     */
    abstract public function down();

    /**
     * Execute raw SQL
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return bool true
     * @throws RuntimeException on SQL errors
     */
    protected function execute($sql, $params = array()) {
        try {
            if (empty($params)) {
                $this->conn->exec($sql);
            } else {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
            }
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException('SQL error: ' . $e->getMessage() . ' [' . self::shorten($sql) . ']');
        }
    }

    /**
     * Run a query and return all rows
     * @param string $sql SQL
     * @param array $params Bound parameters
     * @return array
     */
    protected function fetchAll($sql, $params = array()) {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Run a query and return the first column of the first row
     */
    protected function fetchValue($sql, $params = array()) {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * @param string $tableName Table name
     * @return bool
     */
    protected function tableExists($tableName) {
        return (int)$this->fetchValue(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t",
            array(':t' => $tableName)
        ) > 0;
    }

    /**
     * @param string $tableName Table name
     * @param string $columnName Column name
     * @return bool
     */
    protected function columnExists($tableName, $columnName) {
        return (int)$this->fetchValue(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c",
            array(':t' => $tableName, ':c' => $columnName)
        ) > 0;
    }

    /**
     * Full column type, e.g. "enum('a','b')" (null when the column does not exist)
     */
    protected function columnType($tableName, $columnName) {
        $type = $this->fetchValue(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c",
            array(':t' => $tableName, ':c' => $columnName)
        );
        return $type === false ? null : $type;
    }

    /**
     * @param string $tableName Table name
     * @param string $indexName Index name
     * @return bool
     */
    protected function indexExists($tableName, $indexName) {
        return (int)$this->fetchValue(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i",
            array(':t' => $tableName, ':i' => $indexName)
        ) > 0;
    }

    /**
     * Create a table unless it exists
     * @param string $tableName Table name
     * @param string $definition Column/key definitions (inside the parentheses)
     * @return bool
     */
    protected function createTable($tableName, $definition) {
        self::assertIdentifier($tableName);
        if ($this->tableExists($tableName)) {
            return true;
        }
        return $this->execute("CREATE TABLE `{$tableName}` ({$definition}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Drop a table if it exists
     */
    protected function dropTable($tableName) {
        self::assertIdentifier($tableName);
        if (!$this->tableExists($tableName)) {
            return true;
        }
        return $this->execute("DROP TABLE `{$tableName}`");
    }

    /**
     * Add a column unless it exists
     * @param string $definition e.g. "varchar(500) DEFAULT NULL AFTER `notes`"
     */
    protected function addColumn($tableName, $columnName, $definition) {
        self::assertIdentifier($tableName);
        self::assertIdentifier($columnName);
        if ($this->columnExists($tableName, $columnName)) {
            return true;
        }
        return $this->execute("ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` {$definition}");
    }

    /**
     * Drop a column if it exists
     */
    protected function dropColumn($tableName, $columnName) {
        self::assertIdentifier($tableName);
        self::assertIdentifier($columnName);
        if (!$this->columnExists($tableName, $columnName)) {
            return true;
        }
        return $this->execute("ALTER TABLE `{$tableName}` DROP COLUMN `{$columnName}`");
    }

    /**
     * Change a column definition
     */
    protected function modifyColumn($tableName, $columnName, $definition) {
        self::assertIdentifier($tableName);
        self::assertIdentifier($columnName);
        if (!$this->columnExists($tableName, $columnName)) {
            throw new RuntimeException("Column {$tableName}.{$columnName} does not exist");
        }
        return $this->execute("ALTER TABLE `{$tableName}` MODIFY COLUMN `{$columnName}` {$definition}");
    }

    /**
     * Add an index unless it exists
     * @param string|array $columns Column(s)
     * @param string $type INDEX, UNIQUE or FULLTEXT
     */
    protected function addIndex($tableName, $indexName, $columns, $type = 'INDEX') {
        self::assertIdentifier($tableName);
        self::assertIdentifier($indexName);
        $type = strtoupper($type);
        if (!in_array($type, array('INDEX', 'UNIQUE', 'FULLTEXT'), true)) {
            throw new InvalidArgumentException('Invalid index type: ' . $type);
        }
        if ($this->indexExists($tableName, $indexName)) {
            return true;
        }
        $columns = (array)$columns;
        foreach ($columns as $column) {
            self::assertIdentifier($column);
        }
        $columnList = '`' . implode('`, `', $columns) . '`';
        $keyword = $type === 'INDEX' ? 'INDEX' : $type . ' INDEX';
        return $this->execute("ALTER TABLE `{$tableName}` ADD {$keyword} `{$indexName}` ({$columnList})");
    }

    /**
     * Drop an index if it exists
     */
    protected function dropIndex($tableName, $indexName) {
        self::assertIdentifier($tableName);
        self::assertIdentifier($indexName);
        if (!$this->indexExists($tableName, $indexName)) {
            return true;
        }
        return $this->execute("ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`");
    }

    /**
     * Insert a row
     * @param string $tableName Table name
     * @param array $data Column => value
     * @return bool
     */
    protected function insert($tableName, $data) {
        self::assertIdentifier($tableName);
        $columns = array_keys($data);
        $placeholders = array();
        $params = array();
        foreach ($columns as $i => $column) {
            self::assertIdentifier($column);
            $placeholders[] = ':p' . $i;
            $params[':p' . $i] = $data[$column];
        }
        return $this->execute(
            "INSERT INTO `{$tableName}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")",
            $params
        );
    }

    private static function assertIdentifier($name) {
        if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . (is_string($name) ? $name : gettype($name)));
        }
    }

    private static function shorten($sql) {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return strlen($sql) > 160 ? substr($sql, 0, 157) . '...' : $sql;
    }
}

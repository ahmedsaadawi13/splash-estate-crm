<?php
// FILE: /app/core/Database.php

/**
 * SplashEstate CRM - Database Connection Class
 * Handles PDO database connections with error handling
 */

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $charset;
    private $conn;

    /** @var PDO|null Connection shared by every Database instance in this request */
    private static $sharedConnection = null;

    /** @var Database|null Singleton instance */
    private static $instance = null;

    /**
     * Constructor - Initialize database configuration
     */
    public function __construct() {
        $this->host = DB_HOST;
        $this->db_name = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
        $this->charset = 'utf8mb4';
    }

    /**
     * Get PDO database connection
     * @return PDO connection object
     */
    public function connect() {
        // Re-use one connection per request instead of opening a new one per model
        if (self::$sharedConnection !== null) {
            $this->conn = self::$sharedConnection;
            return $this->conn;
        }

        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            );

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            self::$sharedConnection = $this->conn;

        } catch(PDOException $e) {
            // In test mode, throw exception instead of dying
            if (defined('TEST_MODE') && TEST_MODE) {
                throw $e;
            }
            // Never leak credentials/host details to the browser in production
            error_log('Database connection error: ' . $e->getMessage());
            http_response_code(503);
            if (defined('APP_DEBUG') && APP_DEBUG) {
                echo "Connection Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            } else {
                echo "Service temporarily unavailable.";
            }
            die();
        }

        return $this->conn;
    }

    /**
     * Get the singleton Database instance
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the (shared) PDO connection, connecting if needed
     * @return PDO
     */
    public function getConnection() {
        return $this->connect();
    }

    /**
     * Drop the shared connection (used by tests and long-running CLI scripts)
     */
    public static function resetConnection() {
        self::$sharedConnection = null;
    }
}

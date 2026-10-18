<?php
// FILE: /public/index.php

/**
 * SplashEstate CRM - Application Entry Point
 * All requests are routed through this file
 */

// Load configuration
require_once __DIR__ . '/../config/config.php';

// Harden the session cookie before starting the session
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax'
    ));
} else {
    // PHP < 7.3 has no samesite option; append it to the path attribute
    session_set_cookie_params(0, '/; samesite=Lax', '', $secureCookie, true);
}
session_start();

// Idle session timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// Basic security headers (also set in .htaccess for Apache)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Load core classes
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Model.php';
require_once APP_PATH . '/core/View.php';
require_once APP_PATH . '/core/Router.php';

// Load helper functions
require_once APP_PATH . '/helpers/functions.php';

// Run legacy relative includes ('../app/views/...') from the public directory
chdir(__DIR__);

// Last-resort error handler: log details, show a generic page in production
set_exception_handler(function ($e) {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (APP_DEBUG) {
        echo '<h1>Application error</h1><pre>' . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<h1>Something went wrong</h1><p>Please try again later.</p>';
    }
});

// Initialize router
$router = new Router();

<?php
// FILE: /tests/api_tests.php

/**
 * SplashEstate CRM - API Integration Tests
 * Runs only the REST API tests of the main suite (tests/cases/ApiTest.php)
 * against a throw-away server on the test database.
 *
 * Usage: php tests/api_tests.php
 */

$argv = array($argv[0], 'API');
$argc = 2;
require __DIR__ . '/run_tests.php';

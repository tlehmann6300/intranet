<?php

/**
 * PHPUnit bootstrap file.
 *
 * Loaded before any test runs.  Sets up the minimum required environment so
 * that unit-tested classes can be instantiated without a live database or a
 * running web-server.
 */

// -----------------------------------------------------------------------
// 1. Composer autoloader
// -----------------------------------------------------------------------
require_once __DIR__ . '/../vendor/autoload.php';

// -----------------------------------------------------------------------
// 2. Minimal constants expected by helpers and classes under test
// -----------------------------------------------------------------------
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost');
}

if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'testing');
}

if (!defined('HASH_ALGO')) {
    define('HASH_ALGO', PASSWORD_BCRYPT);
}

// -----------------------------------------------------------------------
// 3. Start a PHP session (required by CSRFHandler, RateLimiter …)
// -----------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------
// 4. Load helpers and classes that are not yet PSR-4 autoloaded
// -----------------------------------------------------------------------
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/../src/RateLimiter.php';

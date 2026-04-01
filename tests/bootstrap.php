<?php
/**
 * PHPUnit Bootstrap
 *
 * Initialises the minimal environment needed for unit tests without
 * bootstrapping the full application (no database, no session, no HTTP headers).
 */

declare(strict_types=1);

// Autoload Composer dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Define application constants used by classes under test
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost');
}
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'testing');
}

// Suppress output from included files during test runs
// (pages include security_headers.php which calls header())
if (!defined('UNIT_TEST')) {
    define('UNIT_TEST', true);
}

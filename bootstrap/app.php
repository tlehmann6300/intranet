<?php

declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Loads configuration, helpers, the Auth class and Composer's autoloader,
 * then builds and returns the DI container.
 *
 * @return \DI\Container
 */

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Application config (defines constants + starts session security settings)
require_once __DIR__ . '/../config/config.php';

// Helpers
require_once __DIR__ . '/../includes/helpers.php';

// Auth class
require_once __DIR__ . '/../src/Auth.php';

// Legacy Database class (needed for models that still use static PDO methods)
require_once __DIR__ . '/../includes/database.php';

// Validator utility
require_once __DIR__ . '/../src/Validator.php';

// Build and return the container
return require __DIR__ . '/container.php';

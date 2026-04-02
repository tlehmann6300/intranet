<?php

declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Loads configuration, helpers, the Auth class and Composer's autoloader,
 * then builds and returns the DI container.
 *
 * Classes in src/ (Models, Handlers, Services, Controllers, Middleware) are
 * discovered automatically by the PSR-4 autoloader (App\ → src/).
 *
 * @return \DI\Container
 */

// Composer autoloader – also boots PSR-4 for App\ → src/
require_once __DIR__ . '/../vendor/autoload.php';

// Application config (defines constants + starts session security settings)
require_once __DIR__ . '/../config/config.php';

// Helpers (global utility functions)
require_once __DIR__ . '/../includes/helpers.php';

// Auth class (not yet namespaced – referenced globally as \Auth throughout the app)
require_once __DIR__ . '/../src/Auth.php';

// Legacy Database class (still provides static PDO connections used by models)
require_once __DIR__ . '/../includes/database.php';

// Validator utility
require_once __DIR__ . '/../src/Validator.php';

// Non-namespaced service classes still loaded explicitly
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../src/CalendarService.php';

// ---------------------------------------------------------------------------
// Legacy class aliases
//
// Controllers and api/ scripts still reference models, handlers and services
// by their old unqualified names (e.g. \Invoice, \CSRFHandler).  Until those
// call sites are migrated to the new App\* namespace the aliases below ensure
// that both names resolve to the same (namespaced) class.
// ---------------------------------------------------------------------------
$legacyAliases = [
    // Models
    'Alumni'              => \App\Models\Alumni::class,
    'AlumniAccessRequest' => \App\Models\AlumniAccessRequest::class,
    'BlogPost'            => \App\Models\BlogPost::class,
    'Event'               => \App\Models\Event::class,
    'EventDocumentation'  => \App\Models\EventDocumentation::class,
    'EventFinancialStats' => \App\Models\EventFinancialStats::class,
    'Idea'                => \App\Models\Idea::class,
    'Inventory'           => \App\Models\Inventory::class,
    'Invoice'             => \App\Models\Invoice::class,
    'JobBoard'            => \App\Models\JobBoard::class,
    'Link'                => \App\Models\Link::class,
    'Member'              => \App\Models\Member::class,
    'NewAlumniRequest'    => \App\Models\NewAlumniRequest::class,
    'Newsletter'          => \App\Models\Newsletter::class,
    'Project'             => \App\Models\Project::class,
    'User'                => \App\Models\User::class,
    'VCard'               => \App\Models\VCard::class,
    // Handlers
    'CSRFHandler'             => \App\Handlers\CSRFHandler::class,
    'AuthHandler'             => \App\Handlers\AuthHandler::class,
    'RateLimiter'             => \App\Handlers\RateLimiter::class,
    'PHPGangsta_GoogleAuthenticator' => \App\Handlers\GoogleAuthenticator::class,
    // Services
    'EasyVereinInventory'  => \App\Services\EasyVereinInventory::class,
    'EasyVereinSync'       => \App\Services\EasyVereinSync::class,
    'MicrosoftGraphService'=> \App\Services\MicrosoftGraphService::class,
    // Utils
    'SecureImageUpload'    => \App\Utils\SecureImageUpload::class,
];

foreach ($legacyAliases as $alias => $class) {
    if (!class_exists($alias, false)) {
        class_alias($class, $alias);
    }
}

// Build and return the DI container (configures Eloquent, Twig, Monolog, etc.)
return require __DIR__ . '/container.php';

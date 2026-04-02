<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use function DI\autowire;
use function DI\factory;

/**
 * Bootstrap the DI container, Twig, Eloquent, and Monolog.
 *
 * @return \DI\Container
 */
$builder = new ContainerBuilder();

$projectRoot = dirname(__DIR__);
$isDev       = defined('ENVIRONMENT') && ENVIRONMENT !== 'production';

// ---------------------------------------------------------------------------
// Eloquent / Illuminate Database
// ---------------------------------------------------------------------------
$capsule = new Capsule();

$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => defined('DB_USER_HOST')    ? DB_USER_HOST    : 'localhost',
    'database'  => defined('DB_USER_NAME')    ? DB_USER_NAME    : '',
    'username'  => defined('DB_USER_USER')    ? DB_USER_USER    : '',
    'password'  => defined('DB_USER_PASS')    ? DB_USER_PASS    : '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
], 'user');

$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => defined('DB_CONTENT_HOST') ? DB_CONTENT_HOST : 'localhost',
    'database'  => defined('DB_CONTENT_NAME') ? DB_CONTENT_NAME : '',
    'username'  => defined('DB_CONTENT_USER') ? DB_CONTENT_USER : '',
    'password'  => defined('DB_CONTENT_PASS') ? DB_CONTENT_PASS : '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
], 'content');

$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => defined('DB_RECH_HOST') ? DB_RECH_HOST : 'localhost',
    'port'      => defined('DB_RECH_PORT') ? DB_RECH_PORT : '3306',
    'database'  => defined('DB_RECH_NAME') ? DB_RECH_NAME : '',
    'username'  => defined('DB_RECH_USER') ? DB_RECH_USER : '',
    'password'  => defined('DB_RECH_PASS') ? DB_RECH_PASS : '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
], 'rech');

$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => defined('DB_INVENTORY_HOST') ? DB_INVENTORY_HOST : 'localhost',
    'port'      => defined('DB_INVENTORY_PORT') ? DB_INVENTORY_PORT : '3306',
    'database'  => defined('DB_INVENTORY_NAME') ? DB_INVENTORY_NAME : '',
    'username'  => defined('DB_INVENTORY_USER') ? DB_INVENTORY_USER : '',
    'password'  => defined('DB_INVENTORY_PASS') ? DB_INVENTORY_PASS : '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
], 'inventory');

$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => defined('DB_NEWS_HOST') ? DB_NEWS_HOST : 'localhost',
    'database'  => defined('DB_NEWS_NAME') ? DB_NEWS_NAME : '',
    'username'  => defined('DB_NEWS_USER') ? DB_NEWS_USER : '',
    'password'  => defined('DB_NEWS_PASS') ? DB_NEWS_PASS : '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
], 'news');

$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => defined('DB_VCARD_HOST') ? DB_VCARD_HOST : 'localhost',
    'port'      => defined('DB_VCARD_PORT') ? DB_VCARD_PORT : '3306',
    'database'  => defined('DB_VCARD_NAME') ? DB_VCARD_NAME : '',
    'username'  => defined('DB_VCARD_USER') ? DB_VCARD_USER : '',
    'password'  => defined('DB_VCARD_PASS') ? DB_VCARD_PASS : '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
], 'vcard');

$capsule->getDatabaseManager()->setDefaultConnection('user');
$capsule->setAsGlobal();
$capsule->bootEloquent();

// ---------------------------------------------------------------------------
// DI container definitions
// ---------------------------------------------------------------------------
$builder->addDefinitions([

    // Monolog Logger (PSR-3 LoggerInterface)
    LoggerInterface::class => factory(function () use ($projectRoot, $isDev): Logger {
        $logger = new Logger('intranet');
        $logDir = $projectRoot . '/logs';

        if ($isDev) {
            // In development: log everything to stderr for easy viewing
            $logger->pushHandler(new StreamHandler('php://stderr', Level::Debug));
        }

        // Rotating daily log file – keeps 14 days of history
        $logger->pushHandler(
            new RotatingFileHandler($logDir . '/app.log', 14, Level::Warning)
        );

        return $logger;
    }),

    Logger::class => factory(function (LoggerInterface $logger): Logger {
        /** @var Logger $logger */
        return $logger;
    }),

    // Twig
    Environment::class => factory(function () use ($projectRoot, $isDev): Environment {
        $loader = new FilesystemLoader($projectRoot . '/templates');
        $twig   = new Environment($loader, [
            'autoescape' => 'html',
            'cache'      => $isDev ? false : $projectRoot . '/storage/twig_cache',
            'debug'      => $isDev,
        ]);
        $twig->addExtension(new \App\Twig\ViteExtension($projectRoot, $isDev));
        return $twig;
    }),

    // Legacy Database class
    Database::class => factory(function (): Database {
        return new Database();
    }),

    // Middleware
    \App\Middleware\AuthMiddleware::class  => autowire(),
    \App\Middleware\AdminMiddleware::class => autowire(),
    \App\Middleware\CsrfMiddleware::class  => autowire(),

    // Controllers (autowired)
    \App\Controllers\AuthController::class          => autowire(),
    \App\Controllers\DashboardController::class     => autowire(),
    \App\Controllers\MemberController::class        => autowire(),
    \App\Controllers\EventController::class         => autowire(),
    \App\Controllers\BlogController::class          => autowire(),
    \App\Controllers\ProjectController::class       => autowire(),
    \App\Controllers\InventoryController::class     => autowire(),
    \App\Controllers\InvoiceController::class       => autowire(),
    \App\Controllers\AlumniController::class        => autowire(),
    \App\Controllers\JobController::class           => autowire(),
    \App\Controllers\NewsletterController::class    => autowire(),
    \App\Controllers\PollController::class          => autowire(),
    \App\Controllers\LinkController::class          => autowire(),
    \App\Controllers\IdeaController::class          => autowire(),
    \App\Controllers\AdminController::class         => autowire(),
    \App\Controllers\PublicController::class        => autowire(),
    \App\Controllers\ProfileController::class       => autowire(),
    \App\Controllers\EventApiController::class      => autowire(),
    \App\Controllers\InventoryApiController::class  => autowire(),
    \App\Controllers\ProjectApiController::class    => autowire(),

    // CLI Commands
    \App\Commands\BackupDatabaseCommand::class       => autowire(),
    \App\Commands\SyncEasyVereinCommand::class       => autowire(),
    \App\Commands\SendBirthdayWishesCommand::class   => autowire(),
    \App\Commands\SendAlumniRemindersCommand::class  => autowire(),
    \App\Commands\SendProfileRemindersCommand::class => autowire(),
    \App\Commands\ProcessMailQueueCommand::class     => autowire(),
    \App\Commands\ReconcileBankPaymentsCommand::class => autowire(),

    // Repositories
    \App\Repositories\UserRepository::class  => factory(function (): \App\Repositories\UserRepository {
        return new \App\Repositories\UserRepository(\Database::getUserDB());
    }),
    \App\Repositories\EventRepository::class => factory(function (): \App\Repositories\EventRepository {
        return new \App\Repositories\EventRepository(\Database::getContentDB());
    }),
]);

return $builder->build();

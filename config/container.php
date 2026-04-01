<?php

/**
 * PHP-DI Container Configuration
 *
 * Wires all application services into the dependency-injection container so
 * that controllers and other consumers can request them by type rather than
 * instantiating or requiring them manually.
 */

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$builder = new ContainerBuilder();

$builder->addDefinitions([

    // -------------------------------------------------------------------------
    // Twig template engine
    // -------------------------------------------------------------------------
    Environment::class => static function (): Environment {
        $loader = new FilesystemLoader(__DIR__ . '/../templates');

        $options = [
            'cache'       => __DIR__ . '/../var/cache/twig',
            'auto_reload' => true,
            'debug'       => (defined('ENVIRONMENT') && ENVIRONMENT !== 'production'),
        ];

        $twig = new Environment($loader, $options);

        if ($options['debug']) {
            $twig->addExtension(new DebugExtension());
        }

        // Global variables available in every template
        $twig->addGlobal('base_url', defined('BASE_URL') ? BASE_URL : '');
        $twig->addGlobal('app_env', defined('ENVIRONMENT') ? ENVIRONMENT : 'production');

        return $twig;
    },

    // -------------------------------------------------------------------------
    // Eloquent / illuminate-database
    // -------------------------------------------------------------------------
    Capsule::class => static function (): Capsule {
        $capsule = new Capsule();

        // User database
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => defined('DB_USER_HOST') ? DB_USER_HOST : '',
            'database'  => defined('DB_USER_NAME') ? DB_USER_NAME : '',
            'username'  => defined('DB_USER_USER') ? DB_USER_USER : '',
            'password'  => defined('DB_USER_PASS') ? DB_USER_PASS : '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ], 'user');

        // Content database
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => defined('DB_CONTENT_HOST') ? DB_CONTENT_HOST : '',
            'database'  => defined('DB_CONTENT_NAME') ? DB_CONTENT_NAME : '',
            'username'  => defined('DB_CONTENT_USER') ? DB_CONTENT_USER : '',
            'password'  => defined('DB_CONTENT_PASS') ? DB_CONTENT_PASS : '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ], 'content');

        // Invoice (Rech) database
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => defined('DB_RECH_HOST') ? DB_RECH_HOST : '',
            'database'  => defined('DB_RECH_NAME') ? DB_RECH_NAME : '',
            'username'  => defined('DB_RECH_USER') ? DB_RECH_USER : '',
            'password'  => defined('DB_RECH_PASS') ? DB_RECH_PASS : '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ], 'invoice');

        // Inventory database
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => defined('DB_INVENTORY_HOST') ? DB_INVENTORY_HOST : '',
            'database'  => defined('DB_INVENTORY_NAME') ? DB_INVENTORY_NAME : '',
            'username'  => defined('DB_INVENTORY_USER') ? DB_INVENTORY_USER : '',
            'password'  => defined('DB_INVENTORY_PASS') ? DB_INVENTORY_PASS : '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ], 'inventory');

        // News database
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => defined('DB_NEWS_HOST') ? DB_NEWS_HOST : '',
            'database'  => defined('DB_NEWS_NAME') ? DB_NEWS_NAME : '',
            'username'  => defined('DB_NEWS_USER') ? DB_NEWS_USER : '',
            'password'  => defined('DB_NEWS_PASS') ? DB_NEWS_PASS : '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ], 'news');

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        return $capsule;
    },

]);

return $builder->build();

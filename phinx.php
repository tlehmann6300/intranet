<?php
/**
 * Phinx Database Migration Configuration
 *
 * Reads the same .env variables as config/config.php via the _env() helper.
 * Usage:  php vendor/bin/phinx migrate -e production
 */

// Bootstrap .env so _env() is available
require_once __DIR__ . '/config/config.php';

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds'      => __DIR__ . '/db/seeds',
    ],

    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => 'production',

        'production' => [
            'adapter' => 'mysql',
            'host'    => _env('DB_USER_HOST', 'localhost'),
            'name'    => _env('DB_USER_NAME'),
            'user'    => _env('DB_USER_USER'),
            'pass'    => _env('DB_USER_PASS'),
            'charset' => 'utf8mb4',
        ],

        'content' => [
            'adapter' => 'mysql',
            'host'    => _env('DB_CONTENT_HOST', 'localhost'),
            'name'    => _env('DB_CONTENT_NAME'),
            'user'    => _env('DB_CONTENT_USER'),
            'pass'    => _env('DB_CONTENT_PASS'),
            'charset' => 'utf8mb4',
        ],

        'rech' => [
            'adapter' => 'mysql',
            'host'    => _env('DB_RECH_HOST', 'localhost'),
            'port'    => (int) _env('DB_RECH_PORT', '3306'),
            'name'    => _env('DB_RECH_NAME'),
            'user'    => _env('DB_RECH_USER'),
            'pass'    => _env('DB_RECH_PASS'),
            'charset' => 'utf8mb4',
        ],

        'inventory' => [
            'adapter' => 'mysql',
            'host'    => _env('DB_INVENTORY_HOST', 'localhost'),
            'port'    => (int) _env('DB_INVENTORY_PORT', '3306'),
            'name'    => _env('DB_INVENTORY_NAME'),
            'user'    => _env('DB_INVENTORY_USER'),
            'pass'    => _env('DB_INVENTORY_PASS'),
            'charset' => 'utf8mb4',
        ],

        'news' => [
            'adapter' => 'mysql',
            'host'    => _env('DB_NEWS_HOST', 'localhost'),
            'name'    => _env('DB_NEWS_NAME'),
            'user'    => _env('DB_NEWS_USER'),
            'pass'    => _env('DB_NEWS_PASS'),
            'charset' => 'utf8mb4',
        ],

        'vcard' => [
            'adapter' => 'mysql',
            'host'    => _env('DB_VCARD_HOST', 'localhost'),
            'port'    => (int) _env('DB_VCARD_PORT', '3306'),
            'name'    => _env('DB_VCARD_NAME'),
            'user'    => _env('DB_VCARD_USER'),
            'pass'    => _env('DB_VCARD_PASS'),
            'charset' => 'utf8mb4',
        ],
    ],

    'version_order' => 'creation',
];

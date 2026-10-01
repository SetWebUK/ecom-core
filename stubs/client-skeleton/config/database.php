<?php

use Pdo\Mysql;

/*
| Only the connections pine/commerce needs are listed; Laravel merges them with its defaults.
|   mysql      the shop database. NO table prefix: parts of the core use raw SQL with unprefixed table names
|   wordpress  the OLD WordPress/WooCommerce database - READ ONLY, used by commerce:import-wordpress
|   scratch    same database, zz_ table prefix: install / importer tests without creating databases (database
|              level only - do not browse the storefront/admin against it)
*/

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'scratch' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            // always zz_…: `php artisan commerce:scratch:drop` refuses any other prefix
            'prefix' => str_starts_with((string) env('DB_SCRATCH_PREFIX', 'zz_'), 'zz_') ? env('DB_SCRATCH_PREFIX', 'zz_') : 'zz_',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],

        'wordpress' => [
            'driver' => 'mysql',
            'host' => env('WP_DB_HOST', '127.0.0.1'),
            'port' => env('WP_DB_PORT', '3306'),
            'database' => env('WP_DB_DATABASE', ''),
            'username' => env('WP_DB_USERNAME', ''),
            'password' => env('WP_DB_PASSWORD', ''),
            'unix_socket' => env('WP_DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => env('WP_DB_PREFIX', 'wp_'),
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
            // the importer can never write to the old site
            'options' => extension_loaded('pdo_mysql') ? [
                Mysql::ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
            ] : [],
        ],
    ],
];

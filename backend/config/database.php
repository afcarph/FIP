<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),

            // Native read/write split: SELECTs go to the replica, writes to the
            // primary. `sticky` keeps a request that has just written reading
            // from the primary so it never observes stale replica data.
            'read' => [
                'host' => [env('DB_READ_HOST', env('DB_HOST', '127.0.0.1'))],
                // A replica is often reached through a proxy or tunnel on a
                // different port, so it gets its own, defaulting to the primary's.
                'port' => env('DB_READ_PORT', env('DB_PORT', '3306')),
            ],
            'write' => [
                'host' => [env('DB_HOST', '127.0.0.1')],
                'port' => env('DB_PORT', '3306'),
            ],
            'sticky' => true,

            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'fip'),
            'username' => env('DB_USERNAME', 'fip'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // The DOE scraper's own database. A separate connection rather than a
        // second set of tables in `mysql`, because the scraper owns this schema
        // and creates it itself — Laravel reads it and never migrates it. It
        // also carries a `fuel_price_history` of its own, with a different
        // shape from the platform's, which two tables in one database could not.
        //
        // Read-only by convention: nothing in the app writes here. The bridge
        // command reads from it and writes to `mysql` through PriceService.
        'doe' => [
            'driver' => 'mysql',
            'host' => env('DOE_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DOE_DB_PORT', '3306'),
            'database' => env('DOE_DB_DATABASE', 'fip_doe'),
            'username' => env('DOE_DB_USERNAME', env('DB_USERNAME', 'fip')),
            'password' => env('DOE_DB_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'fip'), '_').'_db_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],
];

<?php

declare(strict_types=1);

return [
    'host' => env('CLICKHOUSE_HOST'),
    'port' => env('CLICKHOUSE_PORT', 8123),
    'database' => env('CLICKHOUSE_DATABASE'),

    // Reads and writes use separate accounts so the reporting path cannot mutate
    // the event store.
    'write' => [
        'username' => env('CLICKHOUSE_WRITE_USERNAME'),
        'password' => env('CLICKHOUSE_WRITE_PASSWORD'),
    ],

    /*
     * Versioned schema files applied by `php artisan clickhouse:migrate`.
     * Laravel's migrator is never pointed here: it assumes transactional DDL and
     * rollback semantics ClickHouse does not provide.
     */
    'migrations_path' => database_path('clickhouse'),

    'migrations_table' => 'schema_migrations',

    'read' => [
        'username' => env('CLICKHOUSE_READ_USERNAME'),
        'password' => env('CLICKHOUSE_READ_PASSWORD'),
    ],
];

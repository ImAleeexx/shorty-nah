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

    'read' => [
        'username' => env('CLICKHOUSE_READ_USERNAME'),
        'password' => env('CLICKHOUSE_READ_PASSWORD'),
    ],
];

<?php

declare(strict_types=1);

return [
    /*
     * Argon2id is memory-hard, which is what makes a stolen hash expensive to
     * attack offline. Costs are configurable so tests can run cheaply without
     * changing the algorithm under test.
     */
    'driver' => 'argon2id',

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        // 64 MiB and three passes lands near 200ms on the target hardware, which
        // is the point: a login can afford it, a bulk offline attack cannot.
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 3),
        'verify' => true,
    ],

    'rehash_on_login' => true,
];

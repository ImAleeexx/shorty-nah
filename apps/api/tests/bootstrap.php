<?php

declare(strict_types=1);

/*
 * PHPUnit's <env> directives write putenv() and $_ENV. They never write
 * $_SERVER, and Laravel's env() reads $_SERVER first. Under the CLI SAPI with
 * variables_order=EGPCS the Compose environment lands in $_SERVER, so inside
 * the container every value in phpunit.xml lost to DB_CONNECTION=pgsql and
 * CACHE_STORE=redis - force="true" included, because force only decides
 * whether PHPUnit overwrites the layers it does own. The suite then pointed
 * RefreshDatabase at the development Postgres and emptied it.
 *
 * These are invariants of running tests, true wherever they run. Service
 * endpoints are deliberately absent: REDIS_* and CLICKHOUSE_* differ between
 * the host and the container and must follow the environment, and tests that
 * exercise real Redis depend on that.
 *
 * TestEnvironmentGuardTest asserts the result, so a regression here fails a
 * test instead of destroying data.
 */

$invariants = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',

    // Argon2id at production cost adds ~200ms per hash to every authentication
    // test. The algorithm is unchanged; only the work factor drops.
    'ARGON_MEMORY' => '8192',
    'ARGON_TIME' => '1',
    'ARGON_THREADS' => '1',

    // An in-memory database is what makes RefreshDatabase safe.
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',

    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',

    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

foreach ($invariants as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../vendor/autoload.php';

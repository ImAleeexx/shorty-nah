<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * A guard, not a feature test.
 *
 * The suite uses RefreshDatabase, which runs migrate:fresh. If the test
 * environment ever resolves to a real database, that command empties it. That
 * happened: PHPUnit leaves an already-set variable alone, so running the suite
 * inside a container ignored phpunit.xml entirely and wiped the development
 * Postgres.
 *
 * These assertions cost nothing and turn a destructive accident into a failing
 * test.
 */
it('runs against an in-memory database', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');

    // Asked of the connection itself, not only of configuration: a driver that
    // disagreed with the config is exactly the case worth catching.
    expect(DB::connection()->getDriverName())->toBe('sqlite');
});

it('runs in the testing environment', function (): void {
    // Outside it, middleware behaves as it does in production — CSRF stays
    // active — and every state-changing request answers 419.
    expect(app()->environment())->toBe('testing')
        ->and(config('app.env'))->toBe('testing');
});

it('keeps cache, session and queue in process', function (): void {
    // A shared cache or queue would leak state between the suite and a running
    // dev stack in both directions.
    expect(config('cache.default'))->toBe('array')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync');
});

it('never resolves a production-shaped mailer', function (): void {
    expect(config('mail.default'))->toBe('array');
});

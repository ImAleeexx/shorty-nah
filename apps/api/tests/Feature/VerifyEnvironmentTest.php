<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

/**
 * A complete configuration, established here rather than inherited.
 *
 * The suite runs against sqlite with an ambient .env that legitimately lacks
 * datastore credentials, so a test asserting "everything is present" has to say
 * what everything is.
 */
function verifyEnvComplete(): void
{
    Config::set([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://links.example.test',
        'shortynah.domain' => 'links.example.test',
        'shortynah.trusted_proxies' => '172.29.0.0/16',
        'database.connections.pgsql.host' => 'postgres',
        'database.connections.pgsql.port' => '5432',
        'database.connections.pgsql.database' => 'shortynah',
        'database.connections.pgsql.username' => 'shortynah_app',
        'database.connections.pgsql.password' => 'secret',
        'database.connections.pgsql_owner.username' => 'shortynah',
        'database.connections.pgsql_owner.password' => 'secret',
        'database.redis.default.host' => 'redis',
        'database.redis.default.port' => '6379',
        'clickhouse.host' => 'clickhouse',
        'clickhouse.port' => '8123',
        'clickhouse.database' => 'shortynah_events',
        'clickhouse.write.username' => 'writer',
        'clickhouse.write.password' => 'secret',
        'clickhouse.read.username' => 'reader',
        'clickhouse.read.password' => 'secret',
    ]);
}

beforeEach(function (): void {
    verifyEnvComplete();
});

it('passes when every required value is present', function (): void {
    $this->artisan('shortynah:verify-env')->assertExitCode(0);
});

it('refuses to start and names a missing value', function (string $key, string $variable): void {
    Config::set($key, null);

    $this->artisan('shortynah:verify-env')
        ->expectsOutputToContain($variable)
        ->assertExitCode(1);
})->with([
    ['app.key', 'APP_KEY'],
    ['shortynah.domain', 'APP_DOMAIN'],
    ['database.connections.pgsql.password', 'DB_PASSWORD'],
    // A Redis without a password is the scenario the deployment spec names
    // explicitly: dependent services must refuse to start rather than run open.
    ['database.redis.default.host', 'REDIS_HOST'],
    ['clickhouse.read.password', 'CLICKHOUSE_READ_PASSWORD'],
    ['database.connections.pgsql_owner.username', 'DB_OWNER_USERNAME'],
]);

it('refuses a malformed absolute URL', function (): void {
    Config::set('app.url', 'not-a-url');

    $this->artisan('shortynah:verify-env')
        ->expectsOutputToContain('APP_URL')
        ->assertExitCode(1);
});

it('refuses a port outside the valid range', function (string $port): void {
    Config::set('database.connections.pgsql.port', $port);

    $this->artisan('shortynah:verify-env')
        ->expectsOutputToContain('DB_PORT')
        ->assertExitCode(1);
})->with(['0', '65536', 'not-a-port']);

it('refuses a wildcard trusted-proxy configuration', function (string $wildcard): void {
    Config::set('shortynah.trusted_proxies', $wildcard);

    // Trusting every peer lets any client spoof its address, which defeats
    // redirect rate limiting and forges every geographic figure.
    $this->artisan('shortynah:verify-env')
        ->expectsOutputToContain('TRUSTED_PROXIES')
        ->assertExitCode(1);
})->with(['*', '**']);

it('reports every failure at once rather than one per restart', function (): void {
    Config::set('app.key', null);
    Config::set('shortynah.domain', null);

    $this->artisan('shortynah:verify-env')
        ->expectsOutputToContain('APP_KEY')
        ->expectsOutputToContain('APP_DOMAIN')
        ->assertExitCode(1);
});

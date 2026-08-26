<?php

declare(strict_types=1);

namespace App\Providers;

use App\ClickHouse\Connection;
use App\Support\ConfigValue;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the two ClickHouse identities.
 *
 * Both are singletons: the connection holds configuration and an HTTP factory,
 * never request state, so it is safe across a reused worker.
 */
final class ClickHouseServiceProvider extends ServiceProvider
{
    public const READER = 'clickhouse.reader';

    public const WRITER = 'clickhouse.writer';

    public function register(): void
    {
        $this->app->singleton(self::WRITER, fn (): Connection => $this->connection('write'));
        $this->app->singleton(self::READER, fn (): Connection => $this->connection('read'));

        // Unqualified resolution is the reader: reporting is the common case and
        // the safer default if a call site forgets to be explicit.
        $this->app->singleton(Connection::class, fn (): Connection => $this->app->make(self::READER));
    }

    private function connection(string $identity): Connection
    {
        /** @var array<string, mixed> $config */
        $config = config('clickhouse', []);

        $host = ConfigValue::string($config['host'] ?? null, 'CLICKHOUSE_HOST');
        $port = $config['port'] ?? 8123;

        /** @var array<string, mixed> $credentials */
        $credentials = is_array($config[$identity] ?? null) ? $config[$identity] : [];

        return new Connection(
            http: $this->app->make(HttpFactory::class),
            baseUrl: sprintf('http://%s:%s', $host, is_scalar($port) ? (string) $port : '8123'),
            username: ConfigValue::string($credentials['username'] ?? null, 'CLICKHOUSE_'.strtoupper($identity).'_USERNAME'),
            password: ConfigValue::string($credentials['password'] ?? null, 'CLICKHOUSE_'.strtoupper($identity).'_PASSWORD'),
            database: ConfigValue::string($config['database'] ?? null, 'CLICKHOUSE_DATABASE'),
        );
    }
}

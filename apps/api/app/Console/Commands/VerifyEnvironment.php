<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Refuses to let a process start against incomplete configuration.
 *
 * Every failure is collected before reporting so one restart surfaces all of
 * them, rather than making an operator fix values one deploy at a time.
 */
final class VerifyEnvironment extends Command
{
    protected $signature = 'shortynah:verify-env';

    protected $description = 'Verify required environment values are present and well formed';

    /**
     * Config key => environment variable it is read from.
     *
     * @var array<string, string>
     */
    private const REQUIRED = [
        'app.key' => 'APP_KEY',
        'app.url' => 'APP_URL',
        'shortynah.domain' => 'APP_DOMAIN',
        'shortynah.trusted_proxies' => 'TRUSTED_PROXIES',
        'database.connections.pgsql.host' => 'DB_HOST',
        'database.connections.pgsql.database' => 'DB_DATABASE',
        'database.connections.pgsql.username' => 'DB_USERNAME',
        'database.connections.pgsql.password' => 'DB_PASSWORD',
        'database.redis.default.host' => 'REDIS_HOST',
        'clickhouse.host' => 'CLICKHOUSE_HOST',
        'clickhouse.database' => 'CLICKHOUSE_DATABASE',
        'clickhouse.write.username' => 'CLICKHOUSE_WRITE_USERNAME',
        'clickhouse.write.password' => 'CLICKHOUSE_WRITE_PASSWORD',
        'clickhouse.read.username' => 'CLICKHOUSE_READ_USERNAME',
        'clickhouse.read.password' => 'CLICKHOUSE_READ_PASSWORD',
    ];

    /** @var array<string, string> */
    private const URLS = [
        'app.url' => 'APP_URL',
    ];

    /** @var array<string, string> */
    private const PORTS = [
        'database.connections.pgsql.port' => 'DB_PORT',
        'database.redis.default.port' => 'REDIS_PORT',
        'clickhouse.port' => 'CLICKHOUSE_PORT',
    ];

    public function handle(): int
    {
        /** @var list<string> $failures */
        $failures = [];

        foreach (self::REQUIRED as $key => $variable) {
            if ($this->stringValue($key) === null) {
                $failures[] = "{$variable} is required but not set.";
            }
        }

        foreach (self::URLS as $key => $variable) {
            $value = $this->stringValue($key);

            if ($value !== null && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $failures[] = "{$variable} must be an absolute URL, got \"{$value}\".";
            }
        }

        $proxies = $this->stringValue('shortynah.trusted_proxies');

        if ($proxies !== null && in_array(trim($proxies), ['*', '**'], true)) {
            $failures[] = 'TRUSTED_PROXIES must name the edge network, not a wildcard: a trusted wildcard lets any client spoof its address.';
        }

        foreach (self::PORTS as $key => $variable) {
            $value = Config::get($key);

            if ($value === null || $value === '') {
                $failures[] = "{$variable} is required but not set.";

                continue;
            }

            if (! is_numeric($value)) {
                $failures[] = "{$variable} must be a port number, got \"".(is_scalar($value) ? (string) $value : get_debug_type($value)).'".';

                continue;
            }

            $port = (int) $value;

            if ($port < 1 || $port > 65535) {
                $failures[] = "{$variable} must be between 1 and 65535, got {$port}.";
            }
        }

        if ($failures !== []) {
            $this->components->error('Configuration is incomplete; refusing to start.');

            foreach ($failures as $failure) {
                $this->line("  <fg=red>-</> {$failure}");
            }

            return self::FAILURE;
        }

        $this->components->info('Environment verified.');

        return self::SUCCESS;
    }

    private function stringValue(string $key): ?string
    {
        $value = Config::get($key);

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }
}

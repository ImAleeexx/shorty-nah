<?php

declare(strict_types=1);

namespace App\Setup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Reachability of the datastores this instance is configured to use.
 *
 * Every probe targets configured values only. Setup never accepts a host, port
 * or connection string from the caller, because a check that dials whatever it
 * is handed is a request-forgery primitive rather than a diagnostic.
 *
 * Failure reasons come from a fixed vocabulary. A driver's own message routinely
 * contains the DSN, and a DSN contains a password.
 */
final class DependencyProbe
{
    /**
     * @return list<DependencyStatus>
     */
    public function all(): array
    {
        return [
            $this->probe('postgres', function (): void {
                DB::connection()->select('select 1');
            }),
            $this->probe('redis', function (): void {
                Redis::connection()->ping();
            }),
            $this->probe('clickhouse', function (): void {
                $host = config('clickhouse.host');
                $port = config('clickhouse.port');

                if (! is_string($host) || ! is_scalar($port)) {
                    throw new RuntimeException('ClickHouse host or port is not configured.');
                }

                $response = Http::timeout(3)->get("http://{$host}:{$port}/ping");

                if (! $response->successful()) {
                    throw new RuntimeException("ping returned status {$response->status()}.");
                }
            }),
        ];
    }

    public function healthy(): bool
    {
        foreach ($this->all() as $status) {
            if (! $status->healthy) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  callable(): void  $check
     */
    private function probe(string $name, callable $check): DependencyStatus
    {
        try {
            $check();
        } catch (Throwable $e) {
            return new DependencyStatus($name, false, $this->reason($e));
        }

        return new DependencyStatus($name, true);
    }

    private function reason(Throwable $e): string
    {
        $message = mb_strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'refused') => 'The connection was refused.',
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'The connection timed out.',
            str_contains($message, 'authentication'),
            str_contains($message, 'password'),
            str_contains($message, 'access denied') => 'The credentials were rejected.',
            str_contains($message, 'not known'),
            str_contains($message, 'no such host'),
            str_contains($message, 'name or service') => 'The host could not be resolved.',
            str_contains($message, 'not configured') => 'The dependency is not configured.',
            default => 'The dependency did not answer as expected.',
        };
    }
}

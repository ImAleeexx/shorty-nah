<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Makes the health route mean something.
 *
 * The framework's health endpoint answers 200 as long as the process is up,
 * which reports healthy while the application cannot serve a single request.
 * Throwing here turns the endpoint into a readiness check the container runtime
 * can act on.
 */
final class VerifyDependencies
{
    public function handle(): void
    {
        $this->check('postgres', function (): void {
            DB::connection()->select('select 1');
        });

        $this->check('redis', function (): void {
            Redis::connection()->ping();
        });

        $this->check('clickhouse', function (): void {
            $host = config('clickhouse.host');
            $port = config('clickhouse.port');

            if (! is_string($host) || ! is_scalar($port)) {
                throw new RuntimeException('ClickHouse host or port is not configured.');
            }

            $response = Http::timeout(3)->get("http://{$host}:{$port}/ping");

            if (! $response->successful()) {
                throw new RuntimeException("ping returned status {$response->status()}.");
            }
        });
    }

    /**
     * @param  callable(): void  $probe
     */
    private function check(string $dependency, callable $probe): void
    {
        try {
            $probe();
        } catch (Throwable $e) {
            // The dependency name is the actionable part; the underlying message
            // can carry credentials from a DSN, so it is not propagated.
            throw new RuntimeException("Dependency [{$dependency}] is unreachable.", previous: $e);
        }
    }
}

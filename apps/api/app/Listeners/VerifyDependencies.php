<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Setup\DependencyProbe;
use RuntimeException;

/**
 * Makes the health route mean something.
 *
 * The framework's health endpoint answers 200 as long as the process is up,
 * which reports healthy while the application cannot serve a single request.
 * Throwing here turns the endpoint into a readiness check the container runtime
 * can act on.
 *
 * The probes are shared with the setup wizard's connectivity step: readiness and
 * "can this instance be configured" are the same question asked at two moments.
 */
final class VerifyDependencies
{
    public function __construct(private readonly DependencyProbe $probe) {}

    public function handle(): void
    {
        foreach ($this->probe->all() as $status) {
            if (! $status->healthy) {
                // The dependency name is the actionable part; the probe's reason
                // is already free of anything a DSN carries.
                throw new RuntimeException("Dependency [{$status->name}] is unreachable: {$status->reason}");
            }
        }
    }
}

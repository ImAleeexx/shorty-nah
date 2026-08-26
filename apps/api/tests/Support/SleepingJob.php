<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Scaffolding for the graceful-shutdown check, deliberately not in app/.
 *
 * It takes long enough to still be running when a termination signal arrives,
 * which is the only way to observe whether a worker finishes what it is holding
 * or drops it. A queued closure cannot serve here: the serializer reflects on
 * the defining source file, which does not exist for code passed to tinker.
 */
final class SleepingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $marker,
        private readonly int $seconds = 8,
    ) {}

    public function handle(): void
    {
        sleep($this->seconds);

        Cache::put($this->marker, 'finished', 600);
    }
}

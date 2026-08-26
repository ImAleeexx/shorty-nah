<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

it('denies the Horizon dashboard by default', function (): void {
    // The dashboard reveals queue payloads. Until the role model can grant it to
    // owners, no one may see it.
    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('configures a supervisor per queue', function (): void {
    /** @var array<string, mixed> $defaults */
    $defaults = config('horizon.defaults');

    expect(array_keys($defaults))->toBe(['clicks', 'default', 'mail']);

    foreach ($defaults as $supervisor) {
        expect($supervisor['connection'])->toBe('redis');
    }

    expect(config('horizon.defaults.clicks.queue'))->toBe(['clicks'])
        ->and(config('horizon.defaults.default.queue'))->toBe(['default'])
        ->and(config('horizon.defaults.mail.queue'))->toBe(['mail']);
});

it('scales the clicks supervisor by queue size rather than wait time', function (): void {
    // Clicks arrive in bursts and are drained in batches, so depth is the signal
    // that matters, not how long the oldest job has waited.
    expect(config('horizon.defaults.clicks.autoScalingStrategy'))->toBe('size')
        ->and(config('horizon.environments.production.clicks.maxProcesses'))->toBeGreaterThan(
            config('horizon.environments.production.default.maxProcesses')
        );
});

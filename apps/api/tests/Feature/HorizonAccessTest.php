<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('grants the Horizon dashboard to an owner only', function (): void {
    // The dashboard reveals queue payloads: destinations, visitor hashes, job
    // arguments.
    expect(Gate::forUser(User::factory()->owner()->create())->allows('viewHorizon'))->toBeTrue();

    foreach (['admin', 'member', 'viewer'] as $role) {
        expect(Gate::forUser(User::factory()->{$role}()->create())->allows('viewHorizon'))
            ->toBeFalse("role [{$role}] should not reach Horizon");
    }

    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('denies a disabled owner', function (): void {
    $owner = User::factory()->owner()->disabled()->create();

    expect(Gate::forUser($owner)->allows('viewHorizon'))->toBeFalse();
});

it('configures a supervisor per queue', function (): void {
    /** @var array<string, mixed> $defaults */
    $defaults = config('horizon.defaults');

    // Webhooks get their own supervisor rather than a share of `default`: a
    // slow or dead operator endpoint would otherwise sit in front of mail and
    // everything else, and a misconfigured receiver would become this instance's
    // problem.
    expect(array_keys($defaults))->toBe(['clicks', 'default', 'webhooks', 'mail']);

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

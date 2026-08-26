<?php

declare(strict_types=1);

use App\Support\TrustedProxies;

/**
 * Worker reuse is the main source of subtle bugs in this stack: the framework
 * stays booted between requests, so anything holding request state in a
 * singleton or a static property leaks it to the next visitor. These tests are
 * the standing guard for that class of defect.
 */
final class RequestScopedProbe
{
    public ?string $value = null;
}

it('discards a scoped binding between simulated requests', function (): void {
    app()->scoped(RequestScopedProbe::class);

    app(RequestScopedProbe::class)->value = 'first request';

    // What Octane does between requests.
    app()->forgetScopedInstances();

    expect(app(RequestScopedProbe::class)->value)->toBeNull();
});

it('leaks state when a request-scoped service is bound as a singleton', function (): void {
    app()->singleton(RequestScopedProbe::class);

    app(RequestScopedProbe::class)->value = 'first request';
    app()->forgetScopedInstances();

    // Documents the failure mode the rule exists to prevent: a singleton
    // survives the flush, so the second request sees the first request's data.
    expect(app(RequestScopedProbe::class)->value)->toBe('first request');
});

it('flushes bindings named in the octane flush list', function (): void {
    config()->set('octane.flush', [RequestScopedProbe::class]);

    app()->singleton(RequestScopedProbe::class);
    app(RequestScopedProbe::class)->value = 'first request';

    /** @var list<string> $flush */
    $flush = config('octane.flush');

    foreach ($flush as $binding) {
        app()->forgetInstance($binding);
    }

    expect(app(RequestScopedProbe::class)->value)->toBeNull();
});

it('derives the trusted-proxy list from configuration without accumulating', function (): void {
    // A static set once from configuration is safe under worker reuse; a static
    // that grows with each request is not. Repeated resolution must be stable.
    config()->set('shortynah.trusted_proxies', '172.29.0.0/16, 10.0.0.1');

    $first = TrustedProxies::configured();
    $second = TrustedProxies::configured();

    expect($first)->toBe(['172.29.0.0/16', '10.0.0.1'])
        ->and($second)->toBe($first);
});

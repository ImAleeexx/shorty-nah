<?php

declare(strict_types=1);

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/api/__client-address', fn () => response()->json(['ip' => request()->ip()]));
});

afterEach(function (): void {
    TrustProxies::flushState();
});

it('ignores a forwarding header from an untrusted peer', function (): void {
    TrustProxies::at([]);

    $this->getJson('/api/__client-address', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()
        ->assertJson(['ip' => '127.0.0.1']);
});

it('honours a forwarding header from a trusted peer', function (): void {
    TrustProxies::at(['127.0.0.1']);

    $this->getJson('/api/__client-address', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()
        ->assertJson(['ip' => '203.0.113.9']);
});

it('attributes every spoofed request to the same source', function (): void {
    TrustProxies::at([]);

    $seen = [];

    foreach (['203.0.113.1', '203.0.113.2', '198.51.100.5'] as $claimed) {
        $seen[] = $this->getJson('/api/__client-address', ['X-Forwarded-For' => $claimed])
            ->json('ip');
    }

    // A varying header must not produce varying identities, or rate limiting and
    // geographic attribution are both defeated.
    expect(array_unique($seen))->toHaveCount(1);
});

it('refuses a wildcard trusted-proxy configuration', function (): void {
    expect(fn () => TrustedProxies::parse('*'))
        ->toThrow(RuntimeException::class, TrustedProxies::WILDCARD_REJECTED);
});

it('parses a comma separated list of ranges', function (): void {
    expect(TrustedProxies::parse('172.29.0.0/16, 10.0.0.1 ,'))
        ->toBe(['172.29.0.0/16', '10.0.0.1']);
});

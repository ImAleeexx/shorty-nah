<?php

declare(strict_types=1);

use App\Links\ClickCounter;
use App\Models\Domain;
use App\Models\Link;
use App\Providers\ClickHouseServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

function shortHost(string $host = 'go.example.com'): Domain
{
    return Domain::factory()->primary()->create(['host' => $host]);
}

function go(string $host, string $slug): TestResponse
{
    return test()->withServerVariables(['HTTP_HOST' => $host])->get("http://{$host}/{$slug}");
}

beforeEach(function (): void {
    cache()->flush();
    RateLimiter::clear('redirect:127.0.0.1');

    // Reconciliation consults the event store, so these tests need it empty.
    // Without this they inherit whatever the analytics suite left behind.
    $events = app(ClickHouseServiceProvider::WRITER);

    if ($events->ping()) {
        $events->statement('TRUNCATE TABLE IF EXISTS click_events');
        $events->statement('TRUNCATE TABLE IF EXISTS click_hourly');
    }
});

// --- 8.6 constraints, indistinguishably ---

it('refuses an expired link', function (): void {
    $domain = shortHost();
    Link::factory()->forDomain($domain)->withSlug('expired1')->expired()->create();

    go($domain->host, 'expired1')->assertStatus(404);
});

it('refuses a disabled link', function (): void {
    $domain = shortHost();
    Link::factory()->forDomain($domain)->withSlug('disabld1')->disabled()->create();

    go($domain->host, 'disabld1')->assertStatus(404);
});

it('refuses a link that reached its click limit', function (): void {
    $domain = shortHost();
    $link = Link::factory()->forDomain($domain)->withSlug('limited1')->create(['max_clicks' => 2]);

    app(ClickCounter::class)->set($link->id, 2);

    go($domain->host, 'limited1')->assertStatus(404);
});

it('answers identically for every unavailable reason', function (): void {
    $domain = shortHost();

    Link::factory()->forDomain($domain)->withSlug('gone1234')->disabled()->create();
    Link::factory()->forDomain($domain)->withSlug('past1234')->expired()->create();
    $limited = Link::factory()->forDomain($domain)->withSlug('full1234')->create(['max_clicks' => 1]);
    app(ClickCounter::class)->set($limited->id, 1);

    $responses = [
        'disabled' => go($domain->host, 'gone1234'),
        'expired' => go($domain->host, 'past1234'),
        'limit' => go($domain->host, 'full1234'),
        'never existed' => go($domain->host, 'absent12'),
    ];

    $bodies = array_map(fn ($r): string => (string) $r->getContent(), $responses);
    $statuses = array_map(fn ($r): int => $r->status(), $responses);

    // A visitor must not be able to tell a real-but-unavailable slug from one
    // that was never issued; otherwise the redirect path becomes an oracle for
    // discovering which links exist.
    expect(array_unique($statuses))->toHaveCount(1)
        ->and(array_unique($bodies))->toHaveCount(1);
});

it('discloses nothing about the destination in an unavailable response', function (): void {
    $domain = shortHost();
    Link::factory()->forDomain($domain)->withSlug('secret12')->disabled()->create([
        'destination' => 'https://confidential.example.org/board-deck',
    ]);

    $response = go($domain->host, 'secret12');

    expect($response->getContent())->not->toContain('confidential.example.org')
        ->and($response->headers->get('Location'))->toBeNull();
});

// --- 8.7 click counting ---

it('counts a click', function (): void {
    $domain = shortHost();
    $link = Link::factory()->forDomain($domain)->withSlug('counted1')->create();

    $counter = app(ClickCounter::class);

    expect($counter->current($link->id))->toBe(0);

    go($domain->host, 'counted1')->assertStatus(302);

    expect($counter->current($link->id))->toBe(1);

    go($domain->host, 'counted1')->assertStatus(302);

    expect($counter->current($link->id))->toBe(2);
});

it('stops resolving once the limit is reached', function (): void {
    $domain = shortHost();
    Link::factory()->forDomain($domain)->withSlug('twoonly1')->create(['max_clicks' => 2]);

    go($domain->host, 'twoonly1')->assertStatus(302);
    go($domain->host, 'twoonly1')->assertStatus(302);

    // The third arrival finds the limit already met.
    go($domain->host, 'twoonly1')->assertStatus(404);
});

it('counts before responding so a burst cannot exceed the limit', function (): void {
    $domain = shortHost();
    $link = Link::factory()->forDomain($domain)->withSlug('burst123')->create(['max_clicks' => 3]);

    $allowed = 0;

    for ($i = 0; $i < 10; $i++) {
        if (go($domain->host, 'burst123')->status() === 302) {
            $allowed++;
        }
    }

    expect($allowed)->toBe(3)
        ->and(app(ClickCounter::class)->current($link->id))->toBe(3);
});

it('does not count a refused request', function (): void {
    $domain = shortHost();
    $link = Link::factory()->forDomain($domain)->withSlug('refused1')->disabled()->create();

    go($domain->host, 'refused1')->assertStatus(404);

    expect(app(ClickCounter::class)->current($link->id))->toBe(0);
});

// --- 8.9 rate limiting ---

it('rate limits a source driving the redirect path', function (): void {
    $domain = shortHost();
    $link = Link::factory()->forDomain($domain)->withSlug('flooded1')->create();

    $limit = 240;

    for ($i = 0; $i < $limit; $i++) {
        go($domain->host, 'flooded1');
    }

    $countBefore = app(ClickCounter::class)->current($link->id);

    go($domain->host, 'flooded1')->assertStatus(429);

    // A refused request is not a click.
    expect(app(ClickCounter::class)->current($link->id))->toBe($countBefore);
});

it('does not cache a rate limited response', function (): void {
    $domain = shortHost();
    Link::factory()->forDomain($domain)->withSlug('flooded2')->create();

    for ($i = 0; $i < 240; $i++) {
        go($domain->host, 'flooded2');
    }

    expect(go($domain->host, 'flooded2')->headers->get('Cache-Control'))->toContain('no-store');
});

it('keeps a limited link closed after the counter is lost', function (): void {
    $domain = shortHost();
    $link = Link::factory()->forDomain($domain)->withSlug('durable1')->create(['max_clicks' => 2]);

    go($domain->host, 'durable1')->assertStatus(302);
    go($domain->host, 'durable1')->assertStatus(302);
    go($domain->host, 'durable1')->assertStatus(404);

    // Persist the counter, then simulate Redis losing everything.
    $this->artisan('shortynah:reconcile-clicks')->assertExitCode(0);

    expect((int) DB::table('links')->where('id', $link->id)->value('click_count'))->toBe(2);

    cache()->flush();

    // Without a persisted floor in the cache entry, the link would reopen here.
    go($domain->host, 'durable1')->assertStatus(404);
});

it('never moves a persisted count backwards', function (): void {
    $domain = shortHost();
    $link = Link::factory()->forDomain($domain)->withSlug('nobackw1')->create(['click_count' => 50]);

    app(ClickCounter::class)->set($link->id, 3);

    $this->artisan('shortynah:reconcile-clicks')->assertExitCode(0);

    // A counter behind the stored value means Redis was flushed, not that clicks
    // were undone.
    expect((int) DB::table('links')->where('id', $link->id)->value('click_count'))->toBe(50);
});

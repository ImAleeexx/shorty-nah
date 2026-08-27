<?php

declare(strict_types=1);

use App\Links\LinkCache;
use App\Links\RedirectResolver;
use App\Models\Domain;
use App\Models\Link;
use App\Settings\SettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

function shortDomain(string $host = 'go.example.com'): Domain
{
    return Domain::factory()->primary()->create(['host' => $host]);
}

function followSlug(string $host, string $slug): TestResponse
{
    return test()->withServerVariables(['HTTP_HOST' => $host])->get("http://{$host}/{$slug}");
}

beforeEach(function (): void {
    RateLimiter::clear('redirect:127.0.0.1');
    cache()->flush();
});

// --- 8.2 Redis-only resolution ---

it('redirects to the destination', function (): void {
    $domain = shortDomain();
    Link::factory()->forDomain($domain)->withSlug('launch')->create(['destination' => 'https://example.org/page']);

    followSlug($domain->host, 'launch')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.org/page');
});

it('issues no database query on a cache hit', function (): void {
    $domain = shortDomain();
    Link::factory()->forDomain($domain)->withSlug('launch')->create(['destination' => 'https://example.org/page']);

    // Warm it.
    followSlug($domain->host, 'launch')->assertStatus(302);

    DB::enableQueryLog();
    DB::flushQueryLog();

    followSlug($domain->host, 'launch')->assertStatus(302);

    // This is the only route a stranger can drive at volume; it must survive the
    // database being slow or unavailable.
    expect(DB::getQueryLog())->toBeEmpty();
});

it('carries everything it needs in one cache entry', function (): void {
    $domain = shortDomain();
    $link = Link::factory()->forDomain($domain)->withSlug('selfcont')->create([
        'destination' => 'https://example.org/x',
        'max_clicks' => 10,
        'expires_at' => now()->addDay(),
    ]);

    app(RedirectResolver::class)->resolve($domain->host, 'selfcont');

    /** @var array<string, mixed> $entry */
    $entry = cache()->get(LinkCache::key($domain->host, 'selfcont'));

    expect($entry)->toBeArray()
        ->and($entry['destination'])->toBe('https://example.org/x')
        ->and($entry['max_clicks'])->toBe(10)
        ->and($entry['expires_at'])->toBeInt()
        ->and($entry['id'])->toBe($link->id);
});

it('never puts the password hash in the cache entry', function (): void {
    $domain = shortDomain();
    Link::factory()->forDomain($domain)->withSlug('locked12')->passwordProtected()->create();

    app(RedirectResolver::class)->resolve($domain->host, 'locked12');

    $entry = cache()->get(LinkCache::key($domain->host, 'locked12'));

    // A cache dump must not become an offline attack on every protected link.
    expect(json_encode($entry))->not->toContain('argon2id')
        ->and($entry['requires_password'])->toBeTrue();
});

it('does not serve a link on a domain it does not belong to', function (): void {
    $a = shortDomain('a.example.com');
    Domain::factory()->create(['host' => 'b.example.com']);
    Link::factory()->forDomain($a)->withSlug('launch')->create();

    followSlug('b.example.com', 'launch')->assertStatus(404);
});

it('does not serve a link on an unverified domain', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'pending.example.com']);
    Link::factory()->forDomain($domain)->withSlug('launch')->create();

    followSlug('pending.example.com', 'launch')->assertStatus(404);
});

// --- 8.3 negative caching ---

it('issues no database query after the first miss for an unknown slug', function (): void {
    $domain = shortDomain();

    followSlug($domain->host, 'nothere1')->assertStatus(404);

    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach (range(1, 5) as $ignored) {
        followSlug($domain->host, 'nothere1')->assertStatus(404);
    }

    // Without this, walking the slug space is a denial-of-service against
    // Postgres.
    expect(DB::getQueryLog())->toBeEmpty();
});

it('lets a newly created slug resolve without waiting for the negative entry', function (): void {
    $domain = shortDomain();

    followSlug($domain->host, 'soon1234')->assertStatus(404);

    Link::factory()->forDomain($domain)->withSlug('soon1234')->create(['destination' => 'https://example.org/new']);

    // Creation evicts the negative entry through the model observer.
    followSlug($domain->host, 'soon1234')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.org/new');
});

// --- 8.4 single flight ---

it('resolves a cold slug once when several requests arrive together', function (): void {
    $domain = shortDomain();
    Link::factory()->forDomain($domain)->withSlug('popular')->create(['destination' => 'https://example.org/p']);

    $resolver = app(RedirectResolver::class);

    // The cold path also reads the default redirect mode from settings, which is
    // cached after its first use. Warming it keeps this assertion about link
    // resolution rather than about settings.
    app(SettingsStore::class)->get('redirect.default_mode');

    DB::enableQueryLog();
    DB::flushQueryLog();

    // The test client is sequential, so this asserts the property that matters:
    // only the first resolution reaches the database, and holding the lock does
    // not deadlock the callers that follow.
    //
    // Counted as "the cold path, then nothing" rather than as a literal number.
    // The cold path issues two statements now — the link, then its routing rules,
    // which are not joined because a link with five rules would multiply the row
    // and every column with it — and pinning the number here would mean this test
    // fails for a change that is none of its business.
    expect($resolver->resolve($domain->host, 'popular'))->not->toBeNull();

    $cold = count(DB::getQueryLog());

    for ($i = 0; $i < 9; $i++) {
        expect($resolver->resolve($domain->host, 'popular'))->not->toBeNull();
    }

    expect($cold)->toBeGreaterThan(0)
        ->and(DB::getQueryLog())->toHaveCount($cold);
});

it('releases the lock so a later miss can still resolve', function (): void {
    $domain = shortDomain();
    $resolver = app(RedirectResolver::class);

    $resolver->resolve($domain->host, 'firstmis');
    Link::factory()->forDomain($domain)->withSlug('secondms')->create();

    expect($resolver->resolve($domain->host, 'secondms'))->not->toBeNull();
});

// --- 8.5 direct mode response shape ---

it('returns a plain redirect with no tracking markup', function (): void {
    $domain = shortDomain();
    Link::factory()->forDomain($domain)->withSlug('plain123')->create(['destination' => 'https://example.org/p']);

    $response = followSlug($domain->host, 'plain123');

    $body = $response->getContent();

    expect(mb_strlen((string) $body))->toBeLessThan(400)
        ->and($body)->not->toContain('<script')
        ->and($body)->not->toContain('<img');
});

it('forbids caching of a redirect', function (): void {
    $domain = shortDomain();
    Link::factory()->forDomain($domain)->withSlug('nocache1')->create();

    $response = followSlug($domain->host, 'nocache1');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('applies the referrer policy configured on the link', function (): void {
    $domain = shortDomain();
    Link::factory()->forDomain($domain)->withSlug('refpol12')->create(['referrer_policy' => 'no-referrer']);

    expect(followSlug($domain->host, 'refpol12')->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

// --- 8.1 middleware bypass ---

it('runs without a session', function (): void {
    $domain = shortDomain();
    Link::factory()->forDomain($domain)->withSlug('nosess12')->create();

    $response = followSlug($domain->host, 'nosess12');

    $cookieNames = array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());

    expect($cookieNames)->not->toContain((string) config('session.cookie'));
});

it('accepts a password post without a CSRF token', function (): void {
    $domain = shortDomain();
    Link::factory()->forDomain($domain)->withSlug('csrf1234')->passwordProtected('a quiet lantern drifts')->create();

    // A visitor arriving from someone else's shared link has no token to send.
    test()->withServerVariables(['HTTP_HOST' => $domain->host])
        ->post("http://{$domain->host}/csrf1234", ['password' => 'a quiet lantern drifts'])
        ->assertStatus(302);
});

it('does not shadow a named application route', function (): void {
    shortDomain();

    // Asserting the status would test the health check's dependencies, which are
    // not present in the unit environment. What matters here is which route
    // matched: a slug must never take a named application path.
    // Registered paths only. Nothing is bound at the bare /api — only /api/v1/*
    // — so it does fall through to the redirect route, which is harmless: `api`
    // is a reserved slug, so it can never resolve to anything but a 404.
    foreach (['up', 'horizon', 'api/v1/config', 'sanctum/csrf-cookie'] as $path) {
        $route = app('router')->getRoutes()->match(
            Request::create("http://go.example.com/{$path}", 'GET')
        );

        expect($route->getName())->not->toBe('redirect', "[/{$path}] was captured by the redirect route");
    }
});

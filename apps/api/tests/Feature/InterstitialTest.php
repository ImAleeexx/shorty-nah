<?php

declare(strict_types=1);

use App\Clicks\ClickSignalStore;
use App\Clicks\ClickToken;
use App\Clicks\InterstitialPresenter;
use App\Links\ClickCounter;
use App\Models\Domain;
use App\Models\Link;
use App\Settings\SettingsStore;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

function interstitialHost(string $host = 'go.example.test'): Domain
{
    return Domain::factory()->primary()->create(['host' => $host]);
}

function hold(string $host, string $slug): TestResponse
{
    return test()->withServerVariables(['HTTP_HOST' => $host])->get("http://{$host}/{$slug}");
}

beforeEach(function (): void {
    cache()->flush();
    RateLimiter::clear('redirect:127.0.0.1');
    RateLimiter::clear('beacon:127.0.0.1');
});

// --- 9.1 self-contained page ---

it('renders the hold page as a single response with no additional requests', function (): void {
    $domain = interstitialHost();
    Link::factory()->forDomain($domain)->withSlug('holdon12')->interstitial()->create([
        'destination' => 'https://example.org/target',
    ]);

    $body = (string) hold($domain->host, 'holdon12')->assertOk()->getContent();

    // Every asset a browser would fetch separately.
    expect($body)->not->toContain('<link rel="stylesheet"')
        ->and($body)->not->toContain('.css"')
        ->and($body)->not->toContain('<script src')
        ->and($body)->toContain('<style nonce=')
        ->and($body)->toContain('<script nonce=');
});

it('renders the operator branding', function (): void {
    $domain = interstitialHost();
    Link::factory()->forDomain($domain)->withSlug('branded1')->interstitial()->create();

    app(SettingsStore::class)->setMany([
        'instance.name' => 'Externalia Links',
        'branding.accent' => 'oklch(0.62 0.19 26)',
        'branding.radius' => 14,
    ]);

    $body = (string) hold($domain->host, 'branded1')->getContent();

    expect($body)->toContain('Externalia Links')
        ->and($body)->toContain('oklch(0.62 0.19 26)')
        ->and($body)->toContain('--radius: 14px');
});

it('forbids inline script and style without a nonce', function (): void {
    $domain = interstitialHost();
    Link::factory()->forDomain($domain)->withSlug('policy12')->interstitial()->create();

    $policy = (string) hold($domain->host, 'policy12')->headers->get('Content-Security-Policy');

    expect($policy)->not->toContain('unsafe-inline')
        ->and($policy)->not->toContain('unsafe-eval')
        ->and($policy)->toContain("default-src 'none'")
        ->and($policy)->toContain("frame-ancestors 'none'");
});

it('issues a different nonce on every response', function (): void {
    $domain = interstitialHost();
    Link::factory()->forDomain($domain)->withSlug('nonce123')->interstitial()->create();

    $first = (string) hold($domain->host, 'nonce123')->headers->get('Content-Security-Policy');
    $second = (string) hold($domain->host, 'nonce123')->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

it('authorises its own inline blocks with the response nonce', function (): void {
    $domain = interstitialHost();
    Link::factory()->forDomain($domain)->withSlug('matched1')->interstitial()->create();

    $response = hold($domain->host, 'matched1');
    $body = (string) $response->getContent();
    $policy = (string) $response->headers->get('Content-Security-Policy');

    preg_match("/'nonce-([^']+)'/", $policy, $matches);

    expect($matches[1] ?? '')->not->toBe('')
        ->and($body)->toContain('<style nonce="'.$matches[1].'"')
        ->and($body)->toContain('<script nonce="'.$matches[1].'"');
});

// --- 9.4 scripting-free fallback ---

it('reaches the destination without scripting', function (): void {
    $domain = interstitialHost();
    Link::factory()->forDomain($domain)->withSlug('noscrpt1')->interstitial()->create([
        'destination' => 'https://example.org/reachable',
    ]);

    $body = (string) hold($domain->host, 'noscrpt1')->getContent();

    // A meta refresh inside noscript and a visible link: two independent ways
    // through for a visitor whose browser runs nothing.
    expect($body)->toContain('<noscript>')
        ->and($body)->toContain('http-equiv="refresh"')
        ->and($body)->toContain('https://example.org/reachable');
});

// --- 9.5 referrer policy ---

it('applies the per-link referrer policy', function (): void {
    $domain = interstitialHost();
    Link::factory()->forDomain($domain)->withSlug('refpolcy')->interstitial()->create([
        'referrer_policy' => 'no-referrer',
    ]);

    expect(hold($domain->host, 'refpolcy')->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('falls back to a strict referrer policy', function (): void {
    $domain = interstitialHost();
    Link::factory()->forDomain($domain)->withSlug('refdeflt')->interstitial()->create();

    expect(hold($domain->host, 'refdeflt')->headers->get('Referrer-Policy'))
        ->toBe('strict-origin-when-cross-origin');
});

// --- delay bounds ---

it('clamps the configured delay', function (int $configured, int $expected): void {
    app(SettingsStore::class)->set('redirect.interstitial_delay_ms', $configured);

    expect(app(InterstitialPresenter::class)->delay())->toBe($expected);
})->with([
    [0, InterstitialPresenter::MIN_DELAY_MS],
    [50, InterstitialPresenter::MIN_DELAY_MS],
    [1200, 1200],
    [999999, InterstitialPresenter::MAX_DELAY_MS],
]);

// --- 9.2 token integrity ---

it('redeems a freshly issued token once', function (): void {
    $tokens = app(ClickToken::class);
    $issued = $tokens->issue(42);

    $redeemed = $tokens->redeem($issued['token']);

    expect($redeemed)->not->toBeNull()
        ->and($redeemed->linkId)->toBe(42)
        ->and($redeemed->clickId)->toBe($issued['click_id']);

    // Replaying it must fail, or a figure could be inflated by resubmitting.
    expect($tokens->redeem($issued['token']))->toBeNull();
});

it('refuses a forged token', function (string $token): void {
    expect(app(ClickToken::class)->redeem($token))->toBeNull();
})->with([
    'nonsense',
    '1.01JQQQ.9999999999.deadbeef',
    '1.01JQQQ.9999999999',
    '1.01JQQQ.9999999999.',
]);

it('refuses a token whose signature was tampered with', function (): void {
    $tokens = app(ClickToken::class);
    $issued = $tokens->issue(7);

    // Change the link so the beacon would be attributed elsewhere.
    $parts = explode('.', $issued['token']);
    $parts[0] = '999';

    expect($tokens->redeem(implode('.', $parts)))->toBeNull();
});

it('refuses an expired token', function (): void {
    $tokens = app(ClickToken::class);
    $issued = $tokens->issue(7);

    $this->travel(ClickToken::LIFETIME_SECONDS + 5)->seconds();

    expect($tokens->redeem($issued['token']))->toBeNull();
});

// --- 9.3 beacon signals ---

it('stores the reported signals against the click', function (): void {
    $domain = interstitialHost();
    Link::factory()->forDomain($domain)->withSlug('beacon12')->interstitial()->create();

    $issued = app(ClickToken::class)->issue(1);

    $this->postJson('/api/clicks/beacon', [
        'token' => $issued['token'],
        'viewport_width' => 1280,
        'viewport_height' => 720,
        'screen_width' => 2560,
        'screen_height' => 1440,
        'device_pixel_ratio' => 2,
        'timezone' => 'Europe/Madrid',
        'language' => 'es-ES',
        'color_scheme' => 'dark',
        'connection_type' => '4g',
        'dwell_ms' => 1350,
    ])->assertNoContent();

    $signals = app(ClickSignalStore::class)->get($issued['click_id']);

    expect($signals)->not->toBeNull()
        ->and($signals['viewport_width'])->toBe(1280)
        ->and($signals['screen_height'])->toBe(1440)
        ->and($signals['timezone'])->toBe('Europe/Madrid')
        ->and($signals['language'])->toBe('es-ES')
        ->and($signals['color_scheme'])->toBe('dark')
        ->and($signals['connection_type'])->toBe('4g')
        ->and($signals['dwell_ms'])->toBe(1350)
        ->and($signals['device_pixel_ratio'])->toBe(2.0);
});

it('stores nothing for a forged token', function (): void {
    $this->postJson('/api/clicks/beacon', [
        'token' => 'forged.01JQQQ.9999999999.deadbeef',
        'viewport_width' => 1280,
    ])->assertNoContent();

    expect(app(ClickSignalStore::class)->get('01JQQQ'))->toBeNull();
});

it('answers identically whether the token was accepted', function (): void {
    $issued = app(ClickToken::class)->issue(1);

    $accepted = $this->postJson('/api/clicks/beacon', ['token' => $issued['token']]);
    $rejected = $this->postJson('/api/clicks/beacon', ['token' => 'nonsense']);

    // Telling a caller which happened would make the endpoint an oracle.
    expect($accepted->status())->toBe($rejected->status())
        ->and($accepted->getContent())->toBe($rejected->getContent());
});

it('rejects out-of-range measurements rather than storing them', function (): void {
    $issued = app(ClickToken::class)->issue(1);

    $this->postJson('/api/clicks/beacon', [
        'token' => $issued['token'],
        'viewport_width' => 999999,
        'dwell_ms' => -5,
        'device_pixel_ratio' => 99,
        'color_scheme' => 'chartreuse',
        'timezone' => str_repeat('x', 500),
    ])->assertNoContent();

    $signals = app(ClickSignalStore::class)->get($issued['click_id']);

    expect($signals['viewport_width'])->toBeNull()
        ->and($signals['dwell_ms'])->toBeNull()
        ->and($signals['device_pixel_ratio'])->toBeNull()
        ->and($signals['color_scheme'])->toBeNull()
        ->and(mb_strlen((string) $signals['timezone']))->toBeLessThanOrEqual(64);
});

it('counts exactly one click for one interstitial view', function (): void {
    $domain = interstitialHost();
    $link = Link::factory()->forDomain($domain)->withSlug('onceonly')->interstitial()->create();

    hold($domain->host, 'onceonly')->assertOk();

    expect(app(ClickCounter::class)->current($link->id))->toBe(1);
});

<?php

declare(strict_types=1);

use App\Clicks\ClickDeduplicator;
use App\Clicks\ClickEnricher;
use App\Clicks\ClickEnvelope;
use App\Clicks\ClickSignalStore;
use App\Clicks\DatacenterNetworks;
use App\Clicks\GeoLookup;
use App\Clicks\GeoResolver;
use App\Clicks\GeoResult;
use App\Clicks\VisitorHash;
use App\Settings\SettingsStore;
use Illuminate\Support\Str;

/**
 * Counts how often geography was consulted, so the ordering guarantee — filtered
 * traffic never pays for a lookup — can be asserted rather than assumed.
 */
final class CountingGeoLookup implements GeoResolver
{
    public int $lookups = 0;

    /** @var array<string, GeoResult> */
    private array $answers = [];

    public function missingDatabases(): bool
    {
        return false;
    }

    public function answer(string $address, GeoResult $result): void
    {
        $this->answers[$address] = $result;
    }

    public function lookup(?string $address): GeoResult
    {
        $this->lookups++;

        return $address !== null && isset($this->answers[$address])
            ? $this->answers[$address]
            : GeoResult::unknown();
    }
}

function geo(): CountingGeoLookup
{
    if (! app()->bound('test.geo')) {
        $fake = new CountingGeoLookup;
        app()->instance('test.geo', $fake);
        app()->instance(GeoResolver::class, $fake);
    }

    /** @var CountingGeoLookup $fake */
    $fake = app('test.geo');

    return $fake;
}

function enricher(): ClickEnricher
{
    return app(ClickEnricher::class);
}

function envelope(array $overrides = []): ClickEnvelope
{
    return ClickEnvelope::fromArray(array_merge([
        'click_id' => (string) Str::ulid(),
        'link_id' => 1,
        'domain_id' => 1,
        'occurred_at' => '2026-08-26 12:00:00',
        'address' => '93.184.216.34',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        'referrer' => null,
        'redirect_mode' => 'direct',
    ], $overrides));
}

beforeEach(function (): void {
    cache()->flush();
    geo();
    app(SettingsStore::class)->set('analytics.bot_filtering', true);
});

// --- 10.5 user agent parsing ---

it('parses a real browser', function (): void {
    $row = enricher()->enrich(envelope())->toRow();

    expect($row['browser'])->toBe('Chrome')
        ->and($row['operating_system'])->toContain('Mac')
        ->and($row['is_automated'])->toBe(0);
});

it('parses a mobile browser', function (): void {
    $row = enricher()->enrich(envelope([
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    ]))->toRow();

    expect($row['device_type'])->toBe('smartphone')
        ->and($row['operating_system'])->toContain('iOS')
        ->and($row['is_automated'])->toBe(0);
});

// --- 10.3 automated traffic ---

it('classifies a known crawler as automated', function (string $userAgent): void {
    $row = enricher()->enrich(envelope(['user_agent' => $userAgent]))->toRow();

    expect($row['is_automated'])->toBe(1)
        ->and($row['automated_reason'])->not->toBe('');
})->with([
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    'curl/8.4.0',
    'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
]);

it('treats a missing user agent as automated', function (): void {
    $row = enricher()->enrich(envelope(['user_agent' => null]))->toRow();

    // Every real browser sends one.
    expect($row['is_automated'])->toBe(1)
        ->and($row['automated_reason'])->toBe('missing-user-agent');
});

it('classifies a browser user agent from a hosting network as automated', function (): void {
    // Link previewers copy real browser strings; the network is the signal the
    // user agent cannot fake.
    geo()->answer('203.0.113.7', new GeoResult('US', 'Virginia', 'Ashburn', 16509, 'Amazon AWS'));

    $row = enricher()->enrich(envelope(['address' => '203.0.113.7']))->toRow();

    expect($row['is_automated'])->toBe(1)
        ->and($row['automated_reason'])->toContain('datacenter')
        ->and($row['automated_reason'])->toContain('Amazon');
});

it('keeps automated traffic queryable rather than discarding it', function (): void {
    $click = enricher()->enrich(envelope(['user_agent' => 'curl/8.4.0']));
    $row = $click->toRow();

    // Classification, not deletion: a rule can be revised later, a deleted row
    // cannot be recovered.
    expect($click->isCounted())->toBeFalse()
        ->and($row['click_id'])->not->toBe('')
        ->and($row['link_id'])->toBe(1);
});

it('records automated traffic when filtering is disabled', function (): void {
    app(SettingsStore::class)->set('analytics.bot_filtering', false);

    $click = enricher()->enrich(envelope(['user_agent' => 'curl/8.4.0']));

    expect($click->toRow()['is_automated'])->toBe(0)
        ->and($click->isCounted())->toBeTrue();
});

// --- 10.9 ordering: filtered traffic performs no geo lookup ---

it('performs no geo lookup for a client already known to be automated', function (): void {
    $before = geo()->lookups;

    enricher()->enrich(envelope(['user_agent' => 'Googlebot/2.1']));

    // The ordering exists precisely so rejected traffic costs nothing.
    expect(geo()->lookups)->toBe($before);
});

it('performs a geo lookup for a real browser', function (): void {
    $before = geo()->lookups;

    enricher()->enrich(envelope());

    expect(geo()->lookups)->toBe($before + 1);
});

// --- 10.6 visitor hash ---

it('never persists a network address', function (): void {
    $row = enricher()->enrich(envelope(['address' => '93.184.216.34']))->toRow();

    expect(json_encode($row))->not->toContain('93.184.216.34')
        ->and(array_key_exists('address', $row))->toBeFalse()
        ->and($row['visitor_hash'])->toHaveLength(64);
});

it('gives the same visitor the same identifier within a salt period', function (): void {
    $hashes = app(VisitorHash::class);

    expect($hashes->for('93.184.216.34', 'Chrome'))->toBe($hashes->for('93.184.216.34', 'Chrome'));
});

it('distinguishes different visitors', function (): void {
    $hashes = app(VisitorHash::class);

    expect($hashes->for('93.184.216.34', 'Chrome'))->not->toBe($hashes->for('8.8.8.8', 'Chrome'))
        ->and($hashes->for('93.184.216.34', 'Chrome'))->not->toBe($hashes->for('93.184.216.34', 'Firefox'));
});

it('changes the identifier when the salt rotates', function (): void {
    $hashes = app(VisitorHash::class);

    $before = $hashes->for('93.184.216.34', 'Chrome');

    $hashes->rotate();

    // Discarding the previous salt is what makes an identifier
    // non-recomputable afterwards.
    expect($hashes->for('93.184.216.34', 'Chrome'))->not->toBe($before);
});

// --- 10.7 deduplication ---

it('counts a repeated click once inside the window', function (): void {
    $first = enricher()->enrich(envelope());
    $second = enricher()->enrich(envelope());

    expect($first->isCounted())->toBeTrue()
        ->and($second->isCounted())->toBeFalse()
        ->and($second->toRow()['is_duplicate'])->toBe(1);
});

it('counts the same visitor separately on a different link', function (): void {
    $first = enricher()->enrich(envelope(['link_id' => 1]));
    $second = enricher()->enrich(envelope(['link_id' => 2]));

    expect($first->isCounted())->toBeTrue()
        ->and($second->isCounted())->toBeTrue();
});

it('counts again once the window elapses', function (): void {
    expect(enricher()->enrich(envelope())->isCounted())->toBeTrue();

    $this->travel(ClickDeduplicator::WINDOW_SECONDS + 5)->seconds();

    expect(enricher()->enrich(envelope())->isCounted())->toBeTrue();
});

// --- referrer and beacon signals ---

it('keeps only the referrer host', function (): void {
    $row = enricher()->enrich(envelope([
        'referrer' => 'https://Search.Example.org/results?q=secret+query&session=abc123',
    ]))->toRow();

    // A full referrer can carry a search query or a session token; the host is
    // the part worth reporting.
    expect($row['referrer_host'])->toBe('search.example.org')
        ->and(json_encode($row))->not->toContain('secret')
        ->and(json_encode($row))->not->toContain('abc123');
});

it('attaches beacon signals reported for the click', function (): void {
    $click = envelope(['redirect_mode' => 'interstitial']);

    app(ClickSignalStore::class)->put($click->clickId, [
        'viewport_width' => 1280,
        'screen_height' => 1440,
        'timezone' => 'Europe/Madrid',
        'language' => 'es-ES',
        'color_scheme' => 'dark',
        'connection_type' => '4g',
        'dwell_ms' => 1350,
        'device_pixel_ratio' => 2.0,
    ]);

    $row = enricher()->enrich($click)->toRow();

    expect($row['viewport_width'])->toBe(1280)
        ->and($row['screen_height'])->toBe(1440)
        ->and($row['timezone'])->toBe('Europe/Madrid')
        ->and($row['dwell_ms'])->toBe(1350)
        ->and($row['device_pixel_ratio'])->toBe(2.0);
});

it('records a click whose beacon never arrived', function (): void {
    $row = enricher()->enrich(envelope(['redirect_mode' => 'interstitial']))->toRow();

    // A visitor who closes the page before it reports is still a click.
    expect($row['viewport_width'])->toBe(0)
        ->and($row['timezone'])->toBe('')
        ->and($row['click_id'])->not->toBe('');
});

it('records the click with geography marked unknown when the database is missing', function (): void {
    // The real lookup against a path with no databases.
    app()->forgetInstance(GeoResolver::class);
    app()->instance(GeoResolver::class, new GeoLookup('/nonexistent-geoip'));

    $row = app(ClickEnricher::class)->enrich(envelope())->toRow();

    expect($row['country_code'])->toBe('')
        ->and($row['asn'])->toBe(0)
        ->and($row['click_id'])->not->toBe('');
});

it('names the known hosting networks it screens', function (): void {
    expect(DatacenterNetworks::isDatacenter(16509))->toBeTrue()
        ->and(DatacenterNetworks::isDatacenter(0))->toBeFalse()
        ->and(DatacenterNetworks::organisationFor(13335))->toBe('Cloudflare');
});

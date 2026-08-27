<?php

declare(strict_types=1);

use App\Clicks\ArrayClickQueue;
use App\Clicks\ClickEnricher;
use App\Clicks\ClickEnvelope;
use App\Clicks\ClickQueue;
use App\Clicks\GeoResolver;
use App\Clicks\GeoResult;
use App\Models\Domain;
use App\Models\Link;
use App\Settings\SettingsStore;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Geography moved onto the redirect path so country rules can be evaluated
 * before the visitor is sent anywhere. The consequence worth asserting is not
 * the rule — that is phase 3 — but what the queue now carries: a resolved
 * country and a visitor hash instead of an address.
 */
final class StubGeo implements GeoResolver
{
    public int $lookups = 0;

    public function __construct(private readonly GeoResult $answer = new GeoResult('ES', 'Madrid', 'Madrid', 3352, 'Telefonica')) {}

    public function missingDatabases(): bool
    {
        return false;
    }

    public function lookup(?string $address): GeoResult
    {
        $this->lookups++;

        return $address === null ? GeoResult::unknown() : $this->answer;
    }
}

function stubGeo(): StubGeo
{
    if (! app()->bound('test.envelope-geo')) {
        $stub = new StubGeo;
        app()->instance('test.envelope-geo', $stub);
        app()->instance(GeoResolver::class, $stub);
    }

    /** @var StubGeo $stub */
    $stub = app('test.envelope-geo');

    return $stub;
}

function envelopeQueue(): ArrayClickQueue
{
    if (! app()->bound('test.envelope-queue')) {
        $queue = new ArrayClickQueue;
        app()->instance('test.envelope-queue', $queue);
        app()->instance(ClickQueue::class, $queue);
    }

    /** @var ArrayClickQueue $queue */
    $queue = app('test.envelope-queue');

    return $queue;
}

function geographyLink(): Link
{
    $domain = Domain::factory()->create(['host' => 'geo.example.test', 'verified_at' => now()]);

    $link = new Link;
    $link->forceFill([
        'public_id' => (string) Str::ulid(),
        'domain_id' => $domain->id,
        'slug' => 'geoclick',
        'destination' => 'https://example.com/geo',
        'redirect_mode' => 'direct',
        'click_count' => 0,
    ])->save();

    return $link;
}

beforeEach(function (): void {
    cache()->flush();
    RateLimiter::clear('redirect:203.0.113.7');
    envelopeQueue()->clear();
    stubGeo();
    app(SettingsStore::class)->set('analytics.bot_filtering', true);
});

it('queues a resolved country rather than the address that produced it', function (): void {
    geographyLink();

    $this->call('GET', 'http://geo.example.test/geoclick', server: ['REMOTE_ADDR' => '203.0.113.7'])
        ->assertRedirect('https://example.com/geo');

    $queued = envelopeQueue()->drain(10);

    expect($queued)->toHaveCount(1);

    $payload = $queued[0]->toArray();

    expect($payload['country_code'])->toBe('ES')
        ->and($payload['asn'])->toBe(3352)
        ->and($payload['visitor_hash'])->toBeString()->not->toBeEmpty();
});

it('puts no address on the queue at all', function (): void {
    geographyLink();

    $this->call('GET', 'http://geo.example.test/geoclick', server: ['REMOTE_ADDR' => '203.0.113.7']);

    $payload = envelopeQueue()->drain(10)[0]->toArray();

    // Asserted over the whole serialised payload rather than one key: the point
    // is that the address is nowhere in it, not that one field was renamed.
    expect(json_encode($payload))->not->toContain('203.0.113.7')
        ->and($payload)->not->toHaveKey('address');
});

it('does not resolve geography again during enrichment', function (): void {
    geographyLink();

    $this->call('GET', 'http://geo.example.test/geoclick', server: ['REMOTE_ADDR' => '203.0.113.7']);

    $envelope = envelopeQueue()->drain(10)[0];
    $before = stubGeo()->lookups;

    $enriched = app(ClickEnricher::class)->enrich($envelope);

    expect(stubGeo()->lookups)->toBe($before)
        ->and($enriched->toRow()['country_code'])->toBe('ES');
});

it('reads the datacenter decision from the ASN the envelope carries', function (): void {
    // Cloudflare's ASN, resolved at redirect time rather than during enrichment.
    app()->instance(GeoResolver::class, new StubGeo(new GeoResult('US', '', '', 13335, 'Cloudflare')));

    $envelope = ClickEnvelope::fromArray([
        'click_id' => (string) Str::ulid(),
        'link_id' => 1,
        'domain_id' => 1,
        'occurred_at' => '2026-08-27 12:00:00',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
        'referrer' => null,
        'redirect_mode' => 'direct',
        'country_code' => 'US',
        'asn' => 13335,
        'visitor_hash' => str_repeat('a', 64),
    ]);

    $enriched = app(ClickEnricher::class)->enrich($envelope);

    $row = $enriched->toRow();

    expect($row['is_automated'])->toBe(1)
        ->and($row['automated_reason'])->toContain('Cloudflare');
});

// An envelope queued before this change still carries an address and no
// geography. A deploy with a non-empty queue must drain it rather than write a
// batch of unknown countries.
it('still enriches an envelope queued before geography moved', function (): void {
    $legacy = ClickEnvelope::fromArray([
        'click_id' => (string) Str::ulid(),
        'link_id' => 1,
        'domain_id' => 1,
        'occurred_at' => '2026-08-27 12:00:00',
        'address' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
        'referrer' => null,
        'redirect_mode' => 'direct',
    ]);

    $before = stubGeo()->lookups;
    $enriched = app(ClickEnricher::class)->enrich($legacy);

    $row = $enriched->toRow();

    expect(stubGeo()->lookups)->toBe($before + 1)
        ->and($row['country_code'])->toBe('ES')
        ->and($row['visitor_hash'])->not->toBeEmpty();
});

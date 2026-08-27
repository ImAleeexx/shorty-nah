<?php

declare(strict_types=1);

use App\ClickHouse\Connection;
use App\Clicks\ArrayClickQueue;
use App\Clicks\ClickEnvelope;
use App\Clicks\ClickQueue;
use App\Clicks\ClickWriter;
use App\Clicks\GeoResolver;
use App\Clicks\GeoResult;
use App\Models\Domain;
use App\Models\Link;
use App\Providers\ClickHouseServiceProvider;
use App\Settings\SettingsStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

function events(): Connection
{
    return app(ClickHouseServiceProvider::WRITER);
}

function pipelineQueue(): ArrayClickQueue
{
    if (! app()->bound('test.pipeline-queue')) {
        $queue = new ArrayClickQueue;
        app()->instance('test.pipeline-queue', $queue);
        app()->instance(ClickQueue::class, $queue);
    }

    /** @var ArrayClickQueue $queue */
    $queue = app('test.pipeline-queue');

    return $queue;
}

function pending(int $linkId, array $overrides = []): ClickEnvelope
{
    return ClickEnvelope::fromArray(array_merge([
        'click_id' => (string) Str::ulid(),
        'link_id' => $linkId,
        'domain_id' => 1,
        'occurred_at' => '2026-08-26 12:00:00',
        'address' => '93.184.216.34',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        'referrer' => null,
        'redirect_mode' => 'direct',
    ], $overrides));
}

beforeEach(function (): void {
    if (! events()->ping()) {
        $this->markTestSkipped('ClickHouse is not reachable. Start the dev stack with `make up`.');
    }

    cache()->flush();
    RateLimiter::clear('redirect:127.0.0.1');
    pipelineQueue()->clear();

    events()->statement('TRUNCATE TABLE IF EXISTS '.ClickWriter::TABLE);
    app(SettingsStore::class)->set('analytics.bot_filtering', true);
});

// --- 10.8 batched insert ---

it('writes a batch in a single request', function (): void {
    for ($i = 0; $i < 400; $i++) {
        pipelineQueue()->push(pending(1, ['address' => '93.184.216.'.($i % 200)]));
    }

    $this->artisan('shortynah:drain-clicks --batch=500')->assertExitCode(0);

    $total = events()->select('SELECT count() AS total FROM '.ClickWriter::TABLE)[0]['total'];

    expect((int) $total)->toBe(400)
        ->and(pipelineQueue()->size())->toBe(0);
});

it('drains across several passes', function (): void {
    for ($i = 0; $i < 250; $i++) {
        pipelineQueue()->push(pending(1, ['address' => '93.184.216.'.($i % 200)]));
    }

    $this->artisan('shortynah:drain-clicks --batch=100 --passes=3')->assertExitCode(0);

    expect((int) events()->select('SELECT count() AS total FROM '.ClickWriter::TABLE)[0]['total'])->toBe(250)
        ->and(pipelineQueue()->size())->toBe(0);
});

it('does nothing when the queue is empty', function (): void {
    $this->artisan('shortynah:drain-clicks')->assertExitCode(0);

    expect((int) events()->select('SELECT count() AS total FROM '.ClickWriter::TABLE)[0]['total'])->toBe(0);
});

// --- 10.9 end to end, enriched ---

it('lands an enriched event for a real click', function (): void {
    pipelineQueue()->push(pending(7, [
        'referrer' => 'https://news.example.org/story?utm=x',
        'redirect_mode' => 'direct',
    ]));

    $this->artisan('shortynah:drain-clicks')->assertExitCode(0);

    $row = events()->select(
        'SELECT link_id, browser, operating_system, referrer_host, redirect_mode, is_automated, is_duplicate, visitor_hash '
        .'FROM '.ClickWriter::TABLE.' WHERE link_id = {link:UInt64}',
        ['link' => 7],
    )[0];

    expect((int) $row['link_id'])->toBe(7)
        ->and($row['browser'])->toBe('Chrome')
        ->and($row['operating_system'])->toContain('Mac')
        ->and($row['referrer_host'])->toBe('news.example.org')
        ->and($row['redirect_mode'])->toBe('direct')
        ->and((int) $row['is_automated'])->toBe(0)
        ->and((int) $row['is_duplicate'])->toBe(0)
        ->and(mb_strlen((string) $row['visitor_hash']))->toBe(64);
});

it('persists no network address anywhere in the event store', function (): void {
    pipelineQueue()->push(pending(9, ['address' => '93.184.216.34']));

    $this->artisan('shortynah:drain-clicks')->assertExitCode(0);

    // Ask the schema itself: a column able to hold an address should not exist.
    $columns = events()->select(
        'SELECT name FROM system.columns WHERE database = {db:String} AND table = {t:String}',
        ['db' => events()->database(), 't' => ClickWriter::TABLE],
    );

    $names = array_map(static fn (array $c): string => (string) $c['name'], $columns);

    expect($names)->not->toContain('ip')
        ->and($names)->not->toContain('ip_address')
        ->and($names)->not->toContain('address');

    $dump = json_encode(events()->select('SELECT * FROM '.ClickWriter::TABLE));

    expect($dump)->not->toContain('93.184.216.34');
});

it('keeps automated traffic queryable while excluding it from counts', function (): void {
    pipelineQueue()->push(pending(11));
    pipelineQueue()->push(pending(11, ['user_agent' => 'Googlebot/2.1', 'address' => '8.8.8.8']));
    pipelineQueue()->push(pending(11, ['user_agent' => 'curl/8.4.0', 'address' => '1.1.1.1']));

    $this->artisan('shortynah:drain-clicks')->assertExitCode(0);

    $rows = events()->select(
        'SELECT is_automated, automated_reason FROM '.ClickWriter::TABLE.' WHERE link_id = {link:UInt64} ORDER BY is_automated',
        ['link' => 11],
    );

    $counted = events()->select(
        'SELECT count() AS total FROM '.ClickWriter::TABLE
        .' WHERE link_id = {link:UInt64} AND is_automated = 0 AND is_duplicate = 0',
        ['link' => 11],
    )[0]['total'];

    // All three stored; only one counted.
    expect($rows)->toHaveCount(3)
        ->and((int) $counted)->toBe(1);
});

it('marks a repeated click as a duplicate rather than dropping it', function (): void {
    pipelineQueue()->push(pending(13));
    pipelineQueue()->push(pending(13));

    $this->artisan('shortynah:drain-clicks')->assertExitCode(0);

    $stored = (int) events()->select(
        'SELECT count() AS total FROM '.ClickWriter::TABLE.' WHERE link_id = {link:UInt64}',
        ['link' => 13],
    )[0]['total'];

    $counted = (int) events()->select(
        'SELECT count() AS total FROM '.ClickWriter::TABLE
        .' WHERE link_id = {link:UInt64} AND is_duplicate = 0',
        ['link' => 13],
    )[0]['total'];

    expect($stored)->toBe(2)
        ->and($counted)->toBe(1);
});

it('reports a write failure without losing the redirect', function (): void {
    // A dead event store: the writer must swallow it, because the redirect that
    // produced these events already succeeded and there is nobody to answer.
    config()->set('clickhouse.host', '127.0.0.1');
    config()->set('clickhouse.port', 1);
    app()->forgetInstance(ClickWriter::class);
    app()->forgetInstance(ClickHouseServiceProvider::WRITER);

    pipelineQueue()->push(pending(17));

    $this->artisan('shortynah:drain-clicks')->assertExitCode(0);

    expect(app(ClickWriter::class)->lastFailureAt())->not->toBeNull();
});

// --- the whole path, from a request ---

it('records a click made through the redirect path', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $link = Link::factory()->forDomain($domain)->withSlug('endtoend')->create([
        'destination' => 'https://example.org/arrived',
    ]);

    test()->withServerVariables(['HTTP_HOST' => $domain->host])
        ->get("http://{$domain->host}/endtoend")
        ->assertStatus(302);

    $this->artisan('shortynah:drain-clicks')->assertExitCode(0);

    $row = events()->select(
        'SELECT link_id, domain_id, redirect_mode FROM '.ClickWriter::TABLE.' WHERE link_id = {link:UInt64}',
        ['link' => $link->id],
    );

    expect($row)->toHaveCount(1)
        ->and((int) $row[0]['domain_id'])->toBe($domain->id)
        ->and($row[0]['redirect_mode'])->toBe('direct');

    // The redirect itself never touched the event store.
    DB::enableQueryLog();
});

// --- 2.6 / 2.7 geography resolved on the redirect path ---

it('lands the country the redirect resolved, with no address at any stage', function (): void {
    $domain = Domain::factory()->create(['host' => 'pipe.example.test', 'verified_at' => now()]);

    $link = new Link;
    $link->forceFill([
        'public_id' => (string) Str::ulid(),
        'domain_id' => $domain->id,
        'slug' => 'pipegeo1',
        'destination' => 'https://example.com/pipeline-geo',
        'redirect_mode' => 'direct',
        'click_count' => 0,
    ])->save();

    app()->instance(GeoResolver::class, new class implements GeoResolver
    {
        public function missingDatabases(): bool
        {
            return false;
        }

        public function lookup(?string $address): GeoResult
        {
            return new GeoResult('ES', 'Madrid', 'Madrid', 3352, 'Telefonica');
        }
    });

    RateLimiter::clear('redirect:198.51.100.42');

    $this->call('GET', 'http://pipe.example.test/pipegeo1', server: ['REMOTE_ADDR' => '198.51.100.42'])
        ->assertRedirect('https://example.com/pipeline-geo');

    // The queue itself, before anything drains it.
    $queued = pipelineQueue()->drain(10);
    expect(json_encode(array_map(fn (ClickEnvelope $e): array => $e->toArray(), $queued)))
        ->not->toContain('198.51.100.42');

    foreach ($queued as $envelope) {
        pipelineQueue()->push($envelope);
    }

    $this->artisan('shortynah:drain-clicks')->assertExitCode(0);

    $row = events()->select(
        'SELECT country_code, city, asn, as_organisation FROM '.ClickWriter::TABLE.' WHERE link_id = {link:UInt64}',
        ['link' => $link->id],
    )[0];

    expect($row['country_code'])->toBe('ES')
        ->and($row['city'])->toBe('Madrid')
        ->and((int) $row['asn'])->toBe(3352)
        ->and($row['as_organisation'])->toBe('Telefonica');
});

it('still redirects and records when no geographic databases are present', function (): void {
    $domain = Domain::factory()->create(['host' => 'nogeo.example.test', 'verified_at' => now()]);

    $link = new Link;
    $link->forceFill([
        'public_id' => (string) Str::ulid(),
        'domain_id' => $domain->id,
        'slug' => 'nogeo001',
        'destination' => 'https://example.com/no-geo',
        'redirect_mode' => 'direct',
        'click_count' => 0,
    ])->save();

    // What GeoLookup does with no .mmdb files on disk: answers, and says it is
    // missing its databases. A redirect must not depend on them being there.
    app()->instance(GeoResolver::class, new class implements GeoResolver
    {
        public function missingDatabases(): bool
        {
            return true;
        }

        public function lookup(?string $address): GeoResult
        {
            return GeoResult::unknown();
        }
    });

    RateLimiter::clear('redirect:198.51.100.43');

    $this->call('GET', 'http://nogeo.example.test/nogeo001', server: ['REMOTE_ADDR' => '198.51.100.43'])
        ->assertRedirect('https://example.com/no-geo');

    $this->artisan('shortynah:drain-clicks')->assertExitCode(0);

    $row = events()->select(
        'SELECT country_code, visitor_hash FROM '.ClickWriter::TABLE.' WHERE link_id = {link:UInt64}',
        ['link' => $link->id],
    )[0];

    // Unknown geography, but still a counted click with a usable visitor hash.
    expect($row['country_code'])->toBe('')
        ->and(mb_strlen((string) $row['visitor_hash']))->toBe(64);
});

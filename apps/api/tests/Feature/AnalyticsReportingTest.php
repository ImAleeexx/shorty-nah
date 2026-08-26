<?php

declare(strict_types=1);

use App\Analytics\AnalyticsReader;
use App\Analytics\Granularity;
use App\Analytics\RawEventReader;
use App\Analytics\ReportPeriod;
use App\ClickHouse\Connection;
use App\Clicks\ClickWriter;
use App\Links\ClickCounter;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Providers\ClickHouseServiceProvider;
use App\Settings\SettingsStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function store(): Connection
{
    return app(ClickHouseServiceProvider::WRITER);
}

/**
 * Writes straight to the events table so the materialized views fire, which is
 * what these tests are checking.
 *
 * @param  array<string, mixed>  $overrides
 */
function writeEvent(int $linkId, string $occurredAt, array $overrides = []): void
{
    store()->insert(ClickWriter::TABLE, [array_merge([
        'click_id' => (string) Str::ulid(),
        'link_id' => $linkId,
        'domain_id' => 1,
        'occurred_at' => $occurredAt,
        'visitor_hash' => str_pad('v1', 64, '0'),
        'is_automated' => 0,
        'automated_reason' => '',
        'is_duplicate' => 0,
        'country_code' => 'ES',
        'region' => 'Madrid',
        'city' => 'Madrid',
        'asn' => 12345,
        'as_organisation' => 'Example Telecom',
        'device_type' => 'desktop',
        'operating_system' => 'Mac',
        'browser' => 'Chrome',
        'referrer_host' => 'news.example.org',
        'redirect_mode' => 'direct',
        'viewport_width' => 0,
        'viewport_height' => 0,
        'screen_width' => 0,
        'screen_height' => 0,
        'device_pixel_ratio' => 0,
        'timezone' => '',
        'language' => '',
        'color_scheme' => '',
        'connection_type' => '',
        'dwell_ms' => 0,
    ], $overrides)]);
}

function visitor(string $seed): string
{
    return str_pad(substr(hash('sha256', $seed), 0, 64), 64, '0');
}

function period(string $from, string $to, Granularity $granularity = Granularity::Day): ReportPeriod
{
    return new ReportPeriod(Carbon::parse($from), Carbon::parse($to), $granularity);
}

beforeEach(function (): void {
    if (! store()->ping()) {
        $this->markTestSkipped('ClickHouse is not reachable. Start the dev stack with `make up`.');
    }

    cache()->flush();

    foreach (['click_events', 'click_hourly', 'click_by_country', 'click_by_referrer', 'click_by_client'] as $table) {
        store()->statement("TRUNCATE TABLE IF EXISTS {$table}");
    }

    app(SettingsStore::class)->set('analytics.timezone', 'UTC');
});

// --- 11.2 rollups maintained on insert ---

it('updates the rollup as part of the insert, with no job in between', function (): void {
    writeEvent(1, '2026-08-26 10:15:00');
    writeEvent(1, '2026-08-26 10:45:00');
    writeEvent(1, '2026-08-26 11:05:00');

    $rows = store()->select('SELECT bucket, sum(clicks) AS clicks FROM click_hourly WHERE link_id = 1 GROUP BY bucket ORDER BY bucket');

    // No scheduled aggregation ran; the view is populated by the write itself.
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]['clicks'])->toBe(2)
        ->and((int) $rows[1]['clicks'])->toBe(1);
});

it('separates counted from automated and duplicate traffic in the rollup', function (): void {
    writeEvent(1, '2026-08-26 10:00:00');
    writeEvent(1, '2026-08-26 10:00:00', ['is_automated' => 1, 'automated_reason' => 'Googlebot']);
    writeEvent(1, '2026-08-26 10:00:00', ['is_duplicate' => 1]);

    $totals = app(AnalyticsReader::class)->totals(1, period('2026-08-26 00:00:00', '2026-08-27 00:00:00'));

    expect($totals['clicks'])->toBe(3)
        ->and($totals['counted'])->toBe(1)
        ->and($totals['automated'])->toBe(1)
        ->and($totals['duplicates'])->toBe(1);
});

it('excludes automated traffic from the breakdowns', function (): void {
    writeEvent(1, '2026-08-26 10:00:00', ['country_code' => 'ES']);
    writeEvent(1, '2026-08-26 10:00:00', ['country_code' => 'PT', 'is_automated' => 1]);

    $countries = app(AnalyticsReader::class)->byCountry(1, period('2026-08-26 00:00:00', '2026-08-27 00:00:00'));

    expect($countries)->toHaveCount(1)
        ->and($countries[0]['country'])->toBe('ES');
});

// --- 11.3 aggregate-only reads, timezone bucketing ---

it('answers a report without reading the raw events table', function (): void {
    writeEvent(1, '2026-08-26 10:00:00');

    // Asked of the tables a query actually read, not of its text: a text match
    // would count this very probe, because the probe mentions the table.
    $probe = "SELECT count() AS q FROM system.query_log WHERE type = 'QueryFinish' "
        .'AND has(tables, {t:String})';

    store()->statement('SYSTEM FLUSH LOGS');
    $before = store()->select($probe, ['t' => store()->database().'.click_events'])[0]['q'] ?? 0;

    app(AnalyticsReader::class)->series(1, period('2026-08-01 00:00:00', '2026-09-01 00:00:00'));
    app(AnalyticsReader::class)->totals(1, period('2026-08-01 00:00:00', '2026-09-01 00:00:00'));

    store()->statement('SYSTEM FLUSH LOGS');
    $after = store()->select($probe, ['t' => store()->database().'.click_events'])[0]['q'] ?? 0;

    // A dashboard must never scan raw events; that is the whole reason the
    // rollups exist.
    expect((int) $after)->toBe((int) $before);
});

it('buckets days in the instance timezone', function (): void {
    // 23:30 UTC on the 26th is 01:30 on the 27th in Madrid.
    writeEvent(1, '2026-08-26 23:30:00');

    app(SettingsStore::class)->set('analytics.timezone', 'UTC');
    $utc = app(AnalyticsReader::class)->series(1, period('2026-08-26 00:00:00', '2026-08-28 00:00:00'));

    app(SettingsStore::class)->set('analytics.timezone', 'Europe/Madrid');
    $madrid = app(AnalyticsReader::class)->series(1, period('2026-08-26 00:00:00', '2026-08-28 00:00:00'));

    expect($utc[0]['bucket'])->toContain('2026-08-26')
        ->and($madrid[0]['bucket'])->toContain('2026-08-27');
});

it('groups by month when asked', function (): void {
    writeEvent(1, '2026-07-10 10:00:00');
    writeEvent(1, '2026-08-10 10:00:00');
    writeEvent(1, '2026-08-20 10:00:00');

    $series = app(AnalyticsReader::class)->series(
        1,
        period('2026-07-01 00:00:00', '2026-09-01 00:00:00', Granularity::Month),
    );

    expect($series)->toHaveCount(2)
        ->and($series[1]['clicks'])->toBe(2);
});

it('answers a twelve-month report over a large dataset within budget', function (): void {
    // Generated inside ClickHouse so the volume is real without a slow insert
    // loop. Inserting through the events table also exercises the views.
    store()->statement(
        'INSERT INTO '.ClickWriter::TABLE.' (click_id, link_id, domain_id, occurred_at, visitor_hash, country_code, device_type, operating_system, browser, referrer_host, redirect_mode) '
        ."SELECT toString(number), 42, 1, toDateTime('2025-09-01 00:00:00') + INTERVAL (number % 31536000) SECOND, "
        ."lpad(hex(number % 5000), 64, '0'), if(number % 3 = 0, 'ES', 'PT'), 'desktop', 'Mac', 'Chrome', 'news.example.org', 'direct' "
        .'FROM numbers(300000)'
    );

    $started = microtime(true);

    $report = app(AnalyticsReader::class)->series(
        42,
        period('2025-09-01 00:00:00', '2026-09-01 00:00:00', Granularity::Month),
    );

    $elapsed = microtime(true) - $started;

    expect($report)->not->toBeEmpty()
        ->and(array_sum(array_column($report, 'clicks')))->toBe(300000)
        ->and($elapsed)->toBeLessThan(1.0);
});

// --- 11.6 uniques per period, never summed ---

it('merges unique visitors over the period rather than summing buckets', function (): void {
    // The same visitor appears on three days.
    foreach (['2026-08-24 10:00:00', '2026-08-25 10:00:00', '2026-08-26 10:00:00'] as $at) {
        writeEvent(1, $at, ['visitor_hash' => visitor('returning')]);
    }

    $reader = app(AnalyticsReader::class);
    $range = period('2026-08-24 00:00:00', '2026-08-27 00:00:00');

    $series = $reader->series(1, $range);
    $totals = $reader->totals(1, $range);

    $summed = array_sum(array_column($series, 'visitors'));

    // Summing would report three visitors where there was one. This is the whole
    // reason uniques are stored as a merge state.
    expect($summed)->toBe(3)
        ->and($totals['visitors'])->toBe(1);
});

it('counts distinct visitors within a period', function (): void {
    writeEvent(1, '2026-08-26 10:00:00', ['visitor_hash' => visitor('a')]);
    writeEvent(1, '2026-08-26 10:00:00', ['visitor_hash' => visitor('b')]);
    writeEvent(1, '2026-08-26 11:00:00', ['visitor_hash' => visitor('a')]);

    $totals = app(AnalyticsReader::class)->totals(1, period('2026-08-26 00:00:00', '2026-08-27 00:00:00'));

    expect($totals['clicks'])->toBe(3)
        ->and($totals['visitors'])->toBe(2);
});

// --- 11.1 retention ---

it('expires raw events while keeping the rollup totals', function (): void {
    // Start from no TTL so the outcome does not depend on whatever retention a
    // previous run left applied — an existing TTL would drop the old row before
    // this test ever inserted it.
    store()->statement('ALTER TABLE '.ClickWriter::TABLE.' REMOVE TTL');

    writeEvent(1, '2020-01-01 10:00:00');
    writeEvent(1, '2026-08-26 10:00:00');

    expect((int) store()->select('SELECT count() AS t FROM '.ClickWriter::TABLE)[0]['t'])->toBe(2);

    $this->artisan('shortynah:apply-retention --days=30')->assertExitCode(0);

    // TTL removal happens during merges; forcing one makes the effect observable
    // rather than eventual.
    store()->statement('OPTIMIZE TABLE '.ClickWriter::TABLE.' FINAL');

    $remaining = (int) store()->select('SELECT count() AS t FROM '.ClickWriter::TABLE)[0]['t'];
    $rollup = (int) store()->select('SELECT sum(clicks) AS t FROM click_hourly WHERE link_id = 1')[0]['t'];

    expect($remaining)->toBe(1)
        // The point of keeping rollups untouched: last year's totals survive the
        // events behind them.
        ->and($rollup)->toBe(2);
});

it('refuses an implausible retention period', function (string $days): void {
    $this->artisan("shortynah:apply-retention --days={$days}")->assertExitCode(1);
})->with(['0', '-5', '99999']);

// --- 11.4 drill-down ---

it('pages raw events for a link', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $user = User::factory()->member()->create();
    $link = Link::factory()->forDomain($domain)->ownedBy($user)->create();

    for ($i = 0; $i < 30; $i++) {
        writeEvent($link->id, '2026-08-26 10:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00');
    }

    $response = $this->actingAs($user)->getJson("/api/v1/links/{$link->public_id}/events?per_page=10&page=2");

    $response->assertOk()->assertJsonPath('meta.total', 30);

    expect($response->json('events'))->toHaveCount(10);
});

it('hides another member events behind a 404', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $mine = User::factory()->member()->create();
    $link = Link::factory()->forDomain($domain)->ownedBy(User::factory()->member()->create())->create();

    $this->actingAs($mine)->getJson("/api/v1/links/{$link->public_id}/events")->assertStatus(404);
    $this->actingAs($mine)->getJson("/api/v1/links/{$link->public_id}/report")->assertStatus(404);
});

it('returns a report through the API', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $user = User::factory()->admin()->create();
    $link = Link::factory()->forDomain($domain)->create();

    writeEvent($link->id, now()->subDay()->format('Y-m-d H:i:s'));

    $this->actingAs($user)->getJson("/api/v1/links/{$link->public_id}/report")
        ->assertOk()
        ->assertJsonStructure([
            'link' => ['id', 'slug'],
            'period' => ['from', 'to', 'granularity', 'timezone'],
            'totals' => ['clicks', 'counted', 'automated', 'duplicates', 'visitors'],
            'series',
            'countries',
            'referrers',
            'clients' => ['devices', 'operating_systems', 'browsers'],
        ]);
});

// --- 11.5 export ---

it('exports the period events without any address column', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $user = User::factory()->member()->create();
    $link = Link::factory()->forDomain($domain)->ownedBy($user)->create();

    writeEvent($link->id, '2026-08-26 10:00:00');
    writeEvent($link->id, '2026-08-26 11:00:00');
    writeEvent($link->id, '2026-07-01 10:00:00');

    $response = $this->actingAs($user)->get(
        "/api/v1/links/{$link->public_id}/export?from=2026-08-01T00:00:00Z&to=2026-09-01T00:00:00Z"
    );

    $response->assertOk();
    $csv = $response->streamedContent();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv($lines[0]);

    expect($lines)->toHaveCount(3)
        ->and($header)->not->toContain('ip')
        ->and($header)->not->toContain('ip_address')
        ->and($header)->not->toContain('address')
        ->and($header)->toContain('country_code')
        ->and($header)->toContain('click_id');
});

it('names the export after the link and period', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $user = User::factory()->member()->create();
    $link = Link::factory()->forDomain($domain)->ownedBy($user)->withSlug('spring24')->create();

    $response = $this->actingAs($user)->get("/api/v1/links/{$link->public_id}/export?from=2026-08-01T00:00:00Z&to=2026-09-01T00:00:00Z");

    expect($response->headers->get('Content-Disposition'))->toContain('clicks-spring24-2026-08-01.csv')
        ->and($response->headers->get('Content-Type'))->toContain('text/csv');
});

it('refuses an export of another member link', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $mine = User::factory()->member()->create();
    $link = Link::factory()->forDomain($domain)->ownedBy(User::factory()->member()->create())->create();

    $this->actingAs($mine)->get("/api/v1/links/{$link->public_id}/export")->assertStatus(404);
});

it('exposes only the published column set', function (): void {
    // Guards against a future column reaching an export by default.
    expect(RawEventReader::COLUMNS)->not->toContain('visitor_hash')
        ->and(RawEventReader::COLUMNS)->not->toContain('link_id')
        ->and(RawEventReader::COLUMNS)->toContain('click_id');
});

// --- 11.7 reconciling the hot-path counter against the event store ---

it('raises a drifted counter to the recorded total', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $link = Link::factory()->forDomain($domain)->create(['click_count' => 0]);

    // Five real clicks recorded, but the counter only saw two — envelopes lost on
    // a crash, or Redis flushed.
    for ($i = 0; $i < 5; $i++) {
        writeEvent($link->id, '2026-08-26 10:0'.$i.':00', ['visitor_hash' => visitor("v{$i}")]);
    }

    app(ClickCounter::class)->set($link->id, 2);

    $this->artisan('shortynah:reconcile-clicks')->assertExitCode(0);

    expect(app(ClickCounter::class)->current($link->id))->toBe(5)
        ->and((int) DB::table('links')->where('id', $link->id)->value('click_count'))->toBe(5);
});

it('ignores automated and duplicate events when reconciling', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $link = Link::factory()->forDomain($domain)->create(['click_count' => 0]);

    writeEvent($link->id, '2026-08-26 10:00:00', ['visitor_hash' => visitor('real')]);
    writeEvent($link->id, '2026-08-26 10:01:00', ['is_automated' => 1, 'visitor_hash' => visitor('bot')]);
    writeEvent($link->id, '2026-08-26 10:02:00', ['is_duplicate' => 1, 'visitor_hash' => visitor('dupe')]);

    app(ClickCounter::class)->set($link->id, 0);

    $this->artisan('shortynah:reconcile-clicks')->assertExitCode(0);

    // Excluded from counts everywhere, so excluded here too.
    expect(app(ClickCounter::class)->current($link->id))->toBe(1);
});

it('never lowers a count when the event store reports fewer', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $link = Link::factory()->forDomain($domain)->create(['click_count' => 50]);

    writeEvent($link->id, '2026-08-26 10:00:00', ['visitor_hash' => visitor('one')]);

    $this->artisan('shortynah:reconcile-clicks')->assertExitCode(0);

    // Fewer recorded events means data was lost, not that clicks were undone.
    expect((int) DB::table('links')->where('id', $link->id)->value('click_count'))->toBe(50);
});

it('leaves counts alone when the event store is unreachable', function (): void {
    $domain = Domain::factory()->primary()->create(['host' => 'go.example.test']);
    $link = Link::factory()->forDomain($domain)->create(['click_count' => 7]);

    app(ClickCounter::class)->set($link->id, 7);

    config()->set('clickhouse.host', '127.0.0.1');
    config()->set('clickhouse.port', 1);
    app()->forgetInstance(ClickHouseServiceProvider::READER);

    $this->artisan('shortynah:reconcile-clicks')->assertExitCode(0);

    // Unreachable is not the same as zero.
    expect((int) DB::table('links')->where('id', $link->id)->value('click_count'))->toBe(7);
});

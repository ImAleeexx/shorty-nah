<?php

declare(strict_types=1);

use App\ClickHouse\ClickHouseException;
use App\ClickHouse\Connection;
use App\Providers\ClickHouseServiceProvider;

function writer(): Connection
{
    return app(ClickHouseServiceProvider::WRITER);
}

function reader(): Connection
{
    return app(ClickHouseServiceProvider::READER);
}

beforeEach(function (): void {
    if (! writer()->ping()) {
        $this->markTestSkipped(
            'ClickHouse is not reachable at '.config('clickhouse.host').':'.config('clickhouse.port')
            .'. Start the dev stack with `make up`.'
        );
    }

    writer()->statement('DROP TABLE IF EXISTS probe_events');
    writer()->statement(
        'CREATE TABLE probe_events (slug String, country String, clicked_at DateTime) '
        .'ENGINE = MergeTree ORDER BY (slug, clicked_at)'
    );
});

afterEach(function (): void {
    if (writer()->ping()) {
        writer()->statement('DROP TABLE IF EXISTS probe_events');
    }
});

it('inserts a batch in a single request', function (): void {
    $rows = [];

    for ($i = 0; $i < 500; $i++) {
        $rows[] = [
            'slug' => 'slug'.($i % 5),
            'country' => $i % 2 === 0 ? 'ES' : 'PT',
            'clicked_at' => '2026-08-26 10:00:00',
        ];
    }

    expect(writer()->insert('probe_events', $rows))->toBe(500);

    expect(reader()->select('SELECT count() AS total FROM probe_events')[0]['total'])->toBe('500');
});

it('returns no rows and performs no request for an empty batch', function (): void {
    expect(writer()->insert('probe_events', []))->toBe(0);
});

it('binds parameters instead of interpolating them', function (): void {
    writer()->insert('probe_events', [
        ['slug' => 'wanted', 'country' => 'ES', 'clicked_at' => '2026-08-26 10:00:00'],
        ['slug' => 'other', 'country' => 'PT', 'clicked_at' => '2026-08-26 10:00:00'],
    ]);

    $rows = reader()->select(
        'SELECT slug, country FROM probe_events WHERE slug = {slug:String}',
        ['slug' => 'wanted'],
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['slug'])->toBe('wanted');
});

it('treats a hostile binding as a value, not as SQL', function (): void {
    writer()->insert('probe_events', [
        ['slug' => 'safe', 'country' => 'ES', 'clicked_at' => '2026-08-26 10:00:00'],
    ]);

    $rows = reader()->select(
        'SELECT slug FROM probe_events WHERE slug = {slug:String}',
        ['slug' => "' OR 1=1 --"],
    );

    // The table still exists and nothing matched, so the payload was data.
    expect($rows)->toBeEmpty()
        ->and(reader()->select('SELECT count() AS total FROM probe_events')[0]['total'])->toBe('1');
});

it('refuses to write through the reader identity', function (): void {
    expect(fn () => reader()->statement('CREATE TABLE denied_probe (x UInt8) ENGINE = MergeTree ORDER BY x'))
        ->toThrow(ClickHouseException::class);
});

it('rejects an invalid table name', function (): void {
    expect(fn () => writer()->insert('probe_events; DROP TABLE probe_events', [['slug' => 'x']]))
        ->toThrow(ClickHouseException::class, 'Invalid ClickHouse table name');
});

it('reports a failed statement without echoing the statement', function (): void {
    try {
        writer()->statement('SELECT * FROM table_that_does_not_exist');
        $this->fail('Expected a ClickHouseException.');
    } catch (ClickHouseException $e) {
        expect($e->getMessage())->toContain('ClickHouse rejected a statement');
    }
});

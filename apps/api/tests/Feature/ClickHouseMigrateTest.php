<?php

declare(strict_types=1);

use App\ClickHouse\Connection;
use App\Providers\ClickHouseServiceProvider;

function migrationWriter(): Connection
{
    return app(ClickHouseServiceProvider::WRITER);
}

beforeEach(function (): void {
    if (! migrationWriter()->ping()) {
        $this->markTestSkipped('ClickHouse is not reachable. Start the dev stack with `make up`.');
    }

    $this->fixtures = sys_get_temp_dir().'/clickhouse-migrations-'.bin2hex(random_bytes(6));
    mkdir($this->fixtures);

    config()->set('clickhouse.migrations_path', $this->fixtures);
    config()->set('clickhouse.migrations_table', 'probe_schema_migrations');

    migrationWriter()->statement('DROP TABLE IF EXISTS probe_schema_migrations');
    migrationWriter()->statement('DROP TABLE IF EXISTS probe_widgets');
});

afterEach(function (): void {
    if (migrationWriter()->ping()) {
        migrationWriter()->statement('DROP TABLE IF EXISTS probe_schema_migrations');
        migrationWriter()->statement('DROP TABLE IF EXISTS probe_widgets');
    }

    foreach (glob($this->fixtures.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->fixtures);
});

it('reports up to date when there is nothing to apply', function (): void {
    $this->artisan('clickhouse:migrate')
        ->expectsOutputToContain('up to date')
        ->assertExitCode(0);
});

it('applies a pending migration and records it', function (): void {
    file_put_contents(
        $this->fixtures.'/0001_create_probe_widgets.sql',
        "-- a comment containing a semicolon; it must not split the statement\n"
        .'CREATE TABLE IF NOT EXISTS probe_widgets (id UInt32) ENGINE = MergeTree ORDER BY id;'
    );

    $this->artisan('clickhouse:migrate')->assertExitCode(0);

    $tables = migrationWriter()->select(
        'SELECT name FROM system.tables WHERE database = {db:String} AND name = {name:String}',
        ['db' => migrationWriter()->database(), 'name' => 'probe_widgets'],
    );

    expect($tables)->toHaveCount(1);

    $recorded = migrationWriter()->select('SELECT migration FROM probe_schema_migrations');

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]['migration'])->toBe('0001_create_probe_widgets');
});

it('changes nothing when run again', function (): void {
    file_put_contents(
        $this->fixtures.'/0001_create_probe_widgets.sql',
        'CREATE TABLE IF NOT EXISTS probe_widgets (id UInt32) ENGINE = MergeTree ORDER BY id;'
    );

    $this->artisan('clickhouse:migrate')->assertExitCode(0);

    $this->artisan('clickhouse:migrate')
        ->expectsOutputToContain('up to date')
        ->assertExitCode(0);

    expect(migrationWriter()->select('SELECT count() AS total FROM probe_schema_migrations')[0]['total'])
        ->toBe('1');
});

it('applies multiple statements from one file', function (): void {
    file_put_contents(
        $this->fixtures.'/0001_multi.sql',
        'CREATE TABLE IF NOT EXISTS probe_widgets (id UInt32) ENGINE = MergeTree ORDER BY id;'
        ."\n".'ALTER TABLE probe_widgets ADD COLUMN IF NOT EXISTS label String;'
    );

    $this->artisan('clickhouse:migrate')->assertExitCode(0);

    $columns = migrationWriter()->select(
        'SELECT name FROM system.columns WHERE database = {db:String} AND table = {t:String} ORDER BY name',
        ['db' => migrationWriter()->database(), 't' => 'probe_widgets'],
    );

    expect(array_column($columns, 'name'))->toBe(['id', 'label']);
});

it('applies files in version order', function (): void {
    file_put_contents($this->fixtures.'/0002_second.sql', 'ALTER TABLE probe_widgets ADD COLUMN IF NOT EXISTS label String;');
    file_put_contents($this->fixtures.'/0001_first.sql', 'CREATE TABLE IF NOT EXISTS probe_widgets (id UInt32) ENGINE = MergeTree ORDER BY id;');

    // Would fail if 0002 ran before 0001.
    $this->artisan('clickhouse:migrate')->assertExitCode(0);

    expect(migrationWriter()->select('SELECT count() AS total FROM probe_schema_migrations')[0]['total'])
        ->toBe('2');
});

it('lists pending work without applying it under pretend', function (): void {
    file_put_contents($this->fixtures.'/0001_create_probe_widgets.sql', 'CREATE TABLE IF NOT EXISTS probe_widgets (id UInt32) ENGINE = MergeTree ORDER BY id;');

    $this->artisan('clickhouse:migrate --pretend')
        ->expectsOutputToContain('0001_create_probe_widgets')
        ->assertExitCode(0);

    $tables = migrationWriter()->select(
        'SELECT name FROM system.tables WHERE database = {db:String} AND name = {name:String}',
        ['db' => migrationWriter()->database(), 'name' => 'probe_widgets'],
    );

    expect($tables)->toBeEmpty();
});

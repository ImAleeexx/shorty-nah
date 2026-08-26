<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\ClickHouse\ClickHouseException;
use App\ClickHouse\Connection;
use App\Providers\ClickHouseServiceProvider;
use App\Support\ConfigValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Applies versioned ClickHouse schema files.
 *
 * ClickHouse has no transactional DDL, so each file is recorded only after it
 * succeeds. A file that fails halfway leaves the statements before it applied —
 * which is why every migration must be written to be safe to re-run, using
 * IF NOT EXISTS and additive changes only.
 */
final class ClickHouseMigrate extends Command
{
    protected $signature = 'clickhouse:migrate {--pretend : List what would be applied without applying it}';

    protected $description = 'Apply pending ClickHouse schema files';

    public function handle(): int
    {
        $connection = app(ClickHouseServiceProvider::WRITER);

        if (! $connection->ping()) {
            $this->components->error('ClickHouse is unreachable; no schema was applied.');

            return self::FAILURE;
        }

        $this->ensureRegistryExists($connection);

        $applied = $this->applied($connection);
        $pending = array_values(array_diff($this->available(), $applied));

        if ($pending === []) {
            $this->components->info('ClickHouse schema is up to date.');

            return self::SUCCESS;
        }

        foreach ($pending as $migration) {
            if ($this->option('pretend')) {
                $this->line("  <fg=yellow>pending</> {$migration}");

                continue;
            }

            $this->apply($connection, $migration);
            $this->line("  <fg=green>applied</> {$migration}");
        }

        return self::SUCCESS;
    }

    private function registry(): string
    {
        return ConfigValue::string(Config::get('clickhouse.migrations_table'), 'clickhouse.migrations_table');
    }

    private function path(): string
    {
        return ConfigValue::string(Config::get('clickhouse.migrations_path'), 'clickhouse.migrations_path');
    }

    private function ensureRegistryExists(Connection $connection): void
    {
        $connection->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (migration String, applied_at DateTime DEFAULT now()) '
            .'ENGINE = MergeTree ORDER BY migration',
            $this->registry(),
        ));
    }

    /**
     * @return list<string>
     */
    private function applied(Connection $connection): array
    {
        $rows = $connection->select(sprintf('SELECT migration FROM %s', $this->registry()));

        return array_map(
            static fn (array $row): string => is_string($row['migration'] ?? null) ? $row['migration'] : '',
            $rows,
        );
    }

    /**
     * @return list<string>
     */
    private function available(): array
    {
        $files = glob(rtrim($this->path(), '/').'/*.sql');

        if ($files === false) {
            return [];
        }

        $names = array_map(static fn (string $file): string => basename($file, '.sql'), $files);
        sort($names);

        return $names;
    }

    private function apply(Connection $connection, string $migration): void
    {
        $file = rtrim($this->path(), '/')."/{$migration}.sql";
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new ClickHouseException("Unable to read ClickHouse migration [{$migration}].");
        }

        foreach ($this->statements($contents) as $statement) {
            $connection->statement($statement);
        }

        $connection->insert($this->registry(), [['migration' => $migration]]);
    }

    /**
     * @return list<string>
     */
    private function statements(string $contents): array
    {
        // Comments are stripped first so a semicolon inside one cannot split a
        // statement.
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $contents) ?? $contents;

        return array_values(array_filter(
            array_map(
                static fn (string $statement): string => trim($statement),
                explode(';', $withoutComments),
            ),
            static fn (string $statement): bool => $statement !== '',
        ));
    }
}

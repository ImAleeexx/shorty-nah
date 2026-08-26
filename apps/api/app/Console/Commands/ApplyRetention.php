<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\ClickHouse\ClickHouseException;
use App\Clicks\ClickWriter;
use App\Providers\ClickHouseServiceProvider;
use App\Settings\SettingsStore;
use Illuminate\Console\Command;

/**
 * Applies the configured retention period as a TTL on the raw events table.
 *
 * Retention is an operator setting but a TTL is DDL, so the two are reconciled by
 * this command rather than by making the schema depend on a runtime value. It is
 * idempotent: applying the same period again is a no-op.
 *
 * Only raw events expire. The rollups carry no TTL, so a report for a period whose
 * events are gone still has its totals.
 */
final class ApplyRetention extends Command
{
    protected $signature = 'shortynah:apply-retention {--days= : Override the configured retention}';

    protected $description = 'Apply the configured event retention period to the event store';

    public const MIN_DAYS = 1;

    public const MAX_DAYS = 3650;

    public function handle(SettingsStore $settings): int
    {
        $connection = app(ClickHouseServiceProvider::WRITER);

        if (! $connection->ping()) {
            $this->components->error('ClickHouse is unreachable; retention was not applied.');

            return self::FAILURE;
        }

        $override = $this->option('days');
        $days = is_numeric($override)
            ? (int) $override
            : $settings->integer('analytics.retention_days');

        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            $this->components->error(sprintf(
                'Retention must be between %d and %d days; got %d.',
                self::MIN_DAYS,
                self::MAX_DAYS,
                $days,
            ));

            return self::FAILURE;
        }

        try {
            $connection->statement(sprintf(
                'ALTER TABLE %s MODIFY TTL occurred_at + INTERVAL %d DAY',
                ClickWriter::TABLE,
                $days,
            ));
        } catch (ClickHouseException $e) {
            $this->components->error('Could not apply retention: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Raw events now expire after {$days} day(s). Rollups are unaffected.");

        return self::SUCCESS;
    }
}

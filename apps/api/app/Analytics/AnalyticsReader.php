<?php

declare(strict_types=1);

namespace App\Analytics;

use App\ClickHouse\Connection;
use App\Settings\SettingsStore;

/**
 * Answers reports from the rollups.
 *
 * Never touches the raw events table. A twelve-month report over a busy link
 * would otherwise scan every row it ever recorded, and the whole reason the
 * rollups exist is that a dashboard cannot afford that.
 *
 * Unique visitors are merged from stored states rather than summed from
 * per-bucket counts. Summing would double-count anyone who appeared in more than
 * one bucket, which is most people.
 */
final class AnalyticsReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SettingsStore $settings,
    ) {}

    public function timezone(): string
    {
        $configured = $this->settings->string('analytics.timezone');

        return $configured === null || $configured === '' ? 'UTC' : $configured;
    }

    /**
     * Totals for the whole period. Uniques are a single merge over the range, so
     * this figure is not the sum of the series below.
     *
     * @return array{clicks: int, counted: int, automated: int, duplicates: int, visitors: int}
     */
    public function totals(int $linkId, ReportPeriod $period): array
    {
        $rows = $this->connection->select(
            'SELECT sum(clicks) AS clicks, sum(counted) AS counted, sum(automated) AS automated, '
            .'sum(duplicates) AS duplicates, uniqMerge(visitors) AS visitors '
            .'FROM click_hourly WHERE link_id = {link:UInt64} AND bucket >= {from:DateTime} AND bucket < {to:DateTime}',
            $this->bindings($linkId, $period),
        );

        $row = $rows[0] ?? [];

        return [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'counted' => (int) ($row['counted'] ?? 0),
            'automated' => (int) ($row['automated'] ?? 0),
            'duplicates' => (int) ($row['duplicates'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
        ];
    }

    /**
     * @return list<array{bucket: string, clicks: int, counted: int, visitors: int}>
     */
    public function series(int $linkId, ReportPeriod $period): array
    {
        $rows = $this->connection->select(
            'SELECT '.$period->granularity->expression().' AS bucket_at, '
            .'sum(clicks) AS clicks, sum(counted) AS counted, uniqMerge(visitors) AS visitors '
            .'FROM click_hourly WHERE link_id = {link:UInt64} AND bucket >= {from:DateTime} AND bucket < {to:DateTime} '
            .'GROUP BY bucket_at ORDER BY bucket_at',
            $this->bindings($linkId, $period),
        );

        return array_map(static fn (array $row): array => [
            'bucket' => (string) ($row['bucket_at'] ?? ''),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'counted' => (int) ($row['counted'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
        ], $rows);
    }

    /**
     * @return list<array{country: string, counted: int, visitors: int}>
     */
    public function byCountry(int $linkId, ReportPeriod $period, int $limit = 25): array
    {
        $rows = $this->connection->select(
            'SELECT country_code, sum(counted) AS counted, uniqMerge(visitors) AS visitors '
            .'FROM click_by_country WHERE link_id = {link:UInt64} AND bucket >= {from:DateTime} AND bucket < {to:DateTime} '
            .'GROUP BY country_code ORDER BY counted DESC LIMIT {limit:UInt32}',
            $this->bindings($linkId, $period) + ['limit' => $limit],
        );

        return array_map(static fn (array $row): array => [
            'country' => (string) ($row['country_code'] ?? ''),
            'counted' => (int) ($row['counted'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
        ], $rows);
    }

    /**
     * @return list<array{referrer: string, counted: int}>
     */
    public function byReferrer(int $linkId, ReportPeriod $period, int $limit = 25): array
    {
        $rows = $this->connection->select(
            'SELECT referrer_host, sum(counted) AS counted '
            .'FROM click_by_referrer WHERE link_id = {link:UInt64} AND bucket >= {from:DateTime} AND bucket < {to:DateTime} '
            .'GROUP BY referrer_host ORDER BY counted DESC LIMIT {limit:UInt32}',
            $this->bindings($linkId, $period) + ['limit' => $limit],
        );

        return array_map(static fn (array $row): array => [
            'referrer' => (string) ($row['referrer_host'] ?? ''),
            'counted' => (int) ($row['counted'] ?? 0),
        ], $rows);
    }

    /**
     * @return array{devices: list<array{label: string, counted: int}>, operating_systems: list<array{label: string, counted: int}>, browsers: list<array{label: string, counted: int}>}
     */
    public function byClient(int $linkId, ReportPeriod $period, int $limit = 25): array
    {
        return [
            'devices' => $this->clientBreakdown($linkId, $period, 'device_type', $limit),
            'operating_systems' => $this->clientBreakdown($linkId, $period, 'operating_system', $limit),
            'browsers' => $this->clientBreakdown($linkId, $period, 'browser', $limit),
        ];
    }

    /**
     * @return list<array{label: string, counted: int}>
     */
    private function clientBreakdown(int $linkId, ReportPeriod $period, string $column, int $limit): array
    {
        // The column comes from this class's own call sites, never from request
        // input, and is validated so a mistake fails loudly rather than becoming
        // an injection point later.
        if (! in_array($column, ['device_type', 'operating_system', 'browser'], true)) {
            return [];
        }

        $rows = $this->connection->select(
            "SELECT {$column} AS label, sum(counted) AS counted "
            .'FROM click_by_client WHERE link_id = {link:UInt64} AND bucket >= {from:DateTime} AND bucket < {to:DateTime} '
            .'GROUP BY label ORDER BY counted DESC LIMIT {limit:UInt32}',
            $this->bindings($linkId, $period) + ['limit' => $limit],
        );

        return array_map(static fn (array $row): array => [
            'label' => (string) ($row['label'] ?? ''),
            'counted' => (int) ($row['counted'] ?? 0),
        ], $rows);
    }

    /**
     * @return array<string, scalar>
     */
    private function bindings(int $linkId, ReportPeriod $period): array
    {
        return [
            'link' => $linkId,
            'from' => $period->fromUtc(),
            'to' => $period->toUtc(),
            'tz' => $this->timezone(),
        ];
    }
}

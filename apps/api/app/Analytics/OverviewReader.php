<?php

declare(strict_types=1);

namespace App\Analytics;

use App\ClickHouse\Connection;
use App\Settings\SettingsStore;

/**
 * The instance-wide figures the overview screen shows.
 *
 * Every read is scoped to a list of link identifiers rather than querying the
 * whole table, because the overview has to obey the same visibility rule the
 * link list does: an account that may read only its own links must not learn
 * the instance total by looking at the dashboard.
 *
 * Rollups only, like every other dashboard read. Raw events are for drill-down.
 */
final class OverviewReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SettingsStore $settings,
    ) {}

    /**
     * The instance reporting timezone. Day boundaries are applied at query time
     * with it, so changing it reshapes existing reports rather than requiring
     * the rollups to be rebuilt.
     */
    public function timezone(): string
    {
        $configured = $this->settings->string('analytics.timezone');

        return $configured === null || $configured === '' ? 'UTC' : $configured;
    }

    /**
     * @param  list<int>  $linkIds
     * @return array{clicks: int, counted: int, visitors: int, scans: int}
     */
    public function totals(array $linkIds, ReportPeriod $period): array
    {
        if ($linkIds === []) {
            return ['clicks' => 0, 'counted' => 0, 'visitors' => 0, 'scans' => 0];
        }

        $rows = $this->connection->select(
            'SELECT sum(clicks) AS clicks, sum(counted) AS counted, sum(scans) AS scans, '
            .'uniqMerge(visitors) AS visitors '
            .'FROM click_hourly WHERE link_id IN {links:Array(UInt64)} '
            .'AND bucket >= {from:DateTime} AND bucket < {to:DateTime}',
            $this->bindings($linkIds, $period),
        );

        $row = $rows[0] ?? [];

        return [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'counted' => (int) ($row['counted'] ?? 0),
            'visitors' => (int) ($row['visitors'] ?? 0),
            'scans' => (int) ($row['scans'] ?? 0),
        ];
    }

    /**
     * One bucket per day, including days with nothing in them.
     *
     * Gaps are filled here rather than left to the interface: a sparkline that
     * silently closes over a quiet day draws a shape that never happened.
     *
     * @param  list<int>  $linkIds
     * @return list<array{day: string, counted: int}>
     */
    public function daily(array $linkIds, ReportPeriod $period): array
    {
        if ($linkIds === []) {
            return $this->emptyDays($period);
        }

        $rows = $this->connection->select(
            'SELECT toDate(bucket) AS day, sum(counted) AS counted '
            .'FROM click_hourly WHERE link_id IN {links:Array(UInt64)} '
            .'AND bucket >= {from:DateTime} AND bucket < {to:DateTime} '
            .'GROUP BY day ORDER BY day',
            $this->bindings($linkIds, $period),
        );

        $counted = [];

        foreach ($rows as $row) {
            $counted[(string) ($row['day'] ?? '')] = (int) ($row['counted'] ?? 0);
        }

        $days = [];

        foreach ($this->emptyDays($period) as $day) {
            $days[] = ['day' => $day['day'], 'counted' => $counted[$day['day']] ?? 0];
        }

        return $days;
    }

    /**
     * @param  list<int>  $linkIds
     * @return list<array{country_code: string, counted: int}>
     */
    public function byCountry(array $linkIds, ReportPeriod $period, int $limit = 5): array
    {
        if ($linkIds === []) {
            return [];
        }

        $rows = $this->connection->select(
            'SELECT country_code, sum(counted) AS counted '
            .'FROM click_by_country WHERE link_id IN {links:Array(UInt64)} '
            .'AND bucket >= {from:DateTime} AND bucket < {to:DateTime} '
            ."AND country_code != '' "
            .'GROUP BY country_code ORDER BY counted DESC LIMIT {limit:UInt32}',
            $this->bindings($linkIds, $period) + ['limit' => $limit],
        );

        $countries = [];

        foreach ($rows as $row) {
            $countries[] = [
                'country_code' => (string) ($row['country_code'] ?? ''),
                'counted' => (int) ($row['counted'] ?? 0),
            ];
        }

        return $countries;
    }

    /**
     * @return list<array{day: string, counted: int}>
     */
    private function emptyDays(ReportPeriod $period): array
    {
        $days = [];
        $cursor = $period->from->clone()->startOfDay();

        while ($cursor < $period->to) {
            $days[] = ['day' => $cursor->format('Y-m-d'), 'counted' => 0];
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * The connection sends bindings as ClickHouse query parameters and casts
     * each to a string, so an array has to arrive already in ClickHouse's own
     * literal form. The ids are integers read from the database, never from a
     * request, so there is nothing here to escape.
     *
     * @param  list<int>  $linkIds
     * @return array<string, scalar>
     */
    private function bindings(array $linkIds, ReportPeriod $period): array
    {
        return [
            'links' => '['.implode(',', array_map(intval(...), $linkIds)).']',
            'from' => $period->fromUtc(),
            'to' => $period->toUtc(),
        ];
    }
}

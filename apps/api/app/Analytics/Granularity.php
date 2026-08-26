<?php

declare(strict_types=1);

namespace App\Analytics;

enum Granularity: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Month = 'month';

    /**
     * The ClickHouse expression that buckets a UTC hour into this granularity in
     * the instance's timezone.
     *
     * Applying the timezone here rather than in the rollup is what lets an
     * operator change it and have existing reports reshape, instead of needing
     * every aggregate rebuilt.
     */
    public function expression(): string
    {
        return match ($this) {
            self::Hour => 'toStartOfHour(toTimeZone(bucket, {tz:String}))',
            self::Day => 'toStartOfDay(toTimeZone(bucket, {tz:String}))',
            self::Month => 'toStartOfMonth(toTimeZone(bucket, {tz:String}))',
        };
    }
}

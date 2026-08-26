<?php

declare(strict_types=1);

namespace App\Analytics;

use Illuminate\Support\Carbon;

final class ReportPeriod
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Granularity $granularity,
    ) {}

    public function fromUtc(): string
    {
        return $this->from->clone()->utc()->format('Y-m-d H:i:s');
    }

    public function toUtc(): string
    {
        return $this->to->clone()->utc()->format('Y-m-d H:i:s');
    }
}

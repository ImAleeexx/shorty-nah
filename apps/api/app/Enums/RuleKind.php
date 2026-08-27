<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a routing rule matches on.
 *
 * Four kinds, and deliberately not five. Referrer, cookie and query-parameter
 * matching were all considered and refused: each invites a rule set whose
 * behaviour cannot be worked out by reading the link, which is the property that
 * makes first-match-wins ordering comprehensible in the first place.
 */
enum RuleKind: string
{
    case Country = 'country';
    case Device = 'device';
    case Language = 'language';
    case TimeWindow = 'time_window';

    /**
     * Whether this kind needs geography resolved before the redirect returns.
     * Only one does, and it is the reason the hot path resolves it at all.
     */
    public function needsGeography(): bool
    {
        return $this === self::Country;
    }

    public function label(): string
    {
        return match ($this) {
            self::Country => 'Country',
            self::Device => 'Device',
            self::Language => 'Language',
            self::TimeWindow => 'Time window',
        };
    }
}

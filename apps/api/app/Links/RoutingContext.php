<?php

declare(strict_types=1);

namespace App\Links;

/**
 * Everything a rule can be evaluated against, gathered once per request.
 *
 * Assembled by the caller rather than read from the request inside the evaluator,
 * so evaluation is a pure function of its inputs and can be tested without
 * building an HTTP request for every case.
 */
final class RoutingContext
{
    /**
     * @param  list<string>  $languages  Preferred first, already ordered by quality.
     */
    public function __construct(
        public readonly string $countryCode,
        public readonly string $deviceType,
        public readonly array $languages,
        public readonly int $minutesSinceMidnight,
    ) {}
}

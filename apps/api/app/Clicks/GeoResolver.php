<?php

declare(strict_types=1);

namespace App\Clicks;

/**
 * Resolves geography and network from an address.
 *
 * An interface so the enrichment ordering guarantee — that filtered traffic never
 * pays for a lookup — can be asserted by counting calls, rather than taken on
 * trust.
 */
interface GeoResolver
{
    public function lookup(?string $address): GeoResult;

    public function missingDatabases(): bool;
}

<?php

declare(strict_types=1);

namespace App\Links;

use App\Models\Domain;

/**
 * Whether a slug is already claimed on a domain.
 *
 * An interface rather than a private method so the exhaustion path — which is
 * unreachable against a real 58^7 space — can be exercised by a test double
 * instead of being left untested.
 */
interface SlugAvailability
{
    public function isTaken(Domain $domain, string $slug): bool;
}

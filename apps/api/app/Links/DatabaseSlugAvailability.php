<?php

declare(strict_types=1);

namespace App\Links;

use App\Models\Domain;
use App\Models\Link;

final class DatabaseSlugAvailability implements SlugAvailability
{
    public function isTaken(Domain $domain, string $slug): bool
    {
        // Soft-deleted links count: reissuing a retired slug would send traffic
        // meant for it somewhere new.
        return Link::withTrashed()
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->exists();
    }
}

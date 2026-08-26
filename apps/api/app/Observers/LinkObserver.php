<?php

declare(strict_types=1);

namespace App\Observers;

use App\Links\LinkCache;
use App\Models\Link;

/**
 * Keeps the redirect path's cache honest.
 *
 * Every write path goes through here — create, update, delete, restore — so no
 * controller has to remember. The cache is evicted before the change is visible
 * anywhere else, which is why an edited destination takes effect on the very next
 * request.
 */
final class LinkObserver
{
    public function __construct(private readonly LinkCache $cache) {}

    public function saved(Link $link): void
    {
        $this->cache->forgetLink($link);
    }

    public function deleted(Link $link): void
    {
        $this->cache->forgetLink($link);
    }

    public function restored(Link $link): void
    {
        $this->cache->forgetLink($link);
    }

    public function forceDeleted(Link $link): void
    {
        $this->cache->forgetLink($link);
    }
}

<?php

declare(strict_types=1);

namespace App\Observers;

use App\Links\LinkCache;
use App\Models\Link;
use App\Models\LinkRule;

/**
 * Keeps a rule change visible on the next request.
 *
 * Rules travel inside the link's cache entry, so a rule written without evicting
 * that entry is a link that keeps routing by yesterday's rules for up to an hour.
 * The link's own observer cannot see this: nothing on the link row changed.
 */
final class LinkRuleObserver
{
    public function __construct(private readonly LinkCache $cache) {}

    public function saved(LinkRule $rule): void
    {
        $this->forget($rule);
    }

    public function deleted(LinkRule $rule): void
    {
        $this->forget($rule);
    }

    private function forget(LinkRule $rule): void
    {
        $link = $rule->link()->withTrashed()->first();

        if ($link instanceof Link) {
            $this->cache->forgetLink($link);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Links;

use App\Models\Domain;
use App\Models\Link;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * The redirect path's view of a link.
 *
 * Entries are keyed by host and slug together, because slugs are unique per
 * domain: keying on the slug alone would serve one domain's link on another.
 *
 * Invalidation is driven by model events rather than by controllers. A
 * controller that remembers to expire the cache is a controller that will one
 * day forget, and the symptom is a link that keeps sending visitors to an old
 * destination.
 */
final class LinkCache
{
    private const PREFIX = 'shortynah:link:';

    public function __construct(private readonly CacheRepository $cache) {}

    public static function key(string $host, string $slug): string
    {
        return self::PREFIX.mb_strtolower($host).':'.$slug;
    }

    public function forget(string $host, string $slug): void
    {
        $this->cache->forget(self::key($host, $slug));
    }

    /**
     * Forgets every key a link could be cached under, including the host it was
     * previously on if it moved domain.
     */
    public function forgetLink(Link $link): void
    {
        foreach ($this->hostsFor($link) as $host) {
            $this->forget($host, $link->slug);

            $original = $link->getOriginal('slug');

            if (is_string($original) && $original !== $link->slug) {
                $this->forget($host, $original);
            }
        }
    }

    public function has(string $host, string $slug): bool
    {
        return $this->cache->has(self::key($host, $slug));
    }

    /**
     * @return list<string>
     */
    private function hostsFor(Link $link): array
    {
        $hosts = [];

        $domain = $link->domain;

        if ($domain !== null) {
            $hosts[] = $domain->host;
        }

        // A moved link must also be evicted from the domain it came from.
        $originalDomainId = $link->getOriginal('domain_id');

        if (is_int($originalDomainId) && $originalDomainId !== $link->domain_id) {
            $previous = Domain::query()->find($originalDomainId);

            if ($previous !== null) {
                $hosts[] = $previous->host;
            }
        }

        return array_values(array_unique($hosts));
    }
}

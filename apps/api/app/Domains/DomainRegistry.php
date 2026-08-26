<?php

declare(strict_types=1);

namespace App\Domains;

use App\Models\Domain;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * The set of hostnames this instance serves.
 *
 * The certificate-authorization endpoint consults this on the edge's critical
 * path — before a certificate can be issued, so before any request on that
 * hostname can succeed. It therefore answers from cache and never queries the
 * database on a hit.
 *
 * Nothing is memoised in a property: another worker registering a domain must be
 * visible immediately.
 */
final class DomainRegistry
{
    private const CACHE_KEY = 'shortynah:domains:verified';

    public function __construct(private readonly CacheRepository $cache) {}

    public function serves(string $host): bool
    {
        return in_array(Domain::normaliseHost($host), $this->verifiedHosts(), true);
    }

    /**
     * @return list<string>
     */
    public function verifiedHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = $this->cache->rememberForever(
            self::CACHE_KEY,
            static fn (): array => Domain::query()
                ->whereNotNull('verified_at')
                ->orderBy('host')
                ->pluck('host')
                ->all(),
        );

        return $hosts;
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}

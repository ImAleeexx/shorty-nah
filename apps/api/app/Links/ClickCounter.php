<?php

declare(strict_types=1);

namespace App\Links;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Counts clicks on the hot path.
 *
 * The authoritative total lives in the event store, but consulting it per
 * redirect would put a database on the fast path. A Redis counter enforces the
 * limit immediately and a periodic job reconciles it, so the guarantee is that a
 * limited link stops resolving — not that the last click is exact.
 */
final class ClickCounter
{
    private const PREFIX = 'shortynah:clicks:';

    public function __construct(private readonly CacheRepository $cache) {}

    public static function key(int $linkId): string
    {
        return self::PREFIX.$linkId;
    }

    public function current(int $linkId): int
    {
        $value = $this->cache->get(self::key($linkId));

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return int The count after incrementing.
     */
    public function increment(int $linkId): int
    {
        $key = self::key($linkId);

        // add() then increment() so a missing key starts at one rather than
        // being lost; increment() alone is a no-op on some stores when the key
        // is absent.
        $this->cache->add($key, 0);

        $incremented = $this->cache->increment($key);

        return is_int($incremented) ? $incremented : $this->current($linkId);
    }

    public function set(int $linkId, int $clicks): void
    {
        $this->cache->forever(self::key($linkId), $clicks);
    }

    public function forget(int $linkId): void
    {
        $this->cache->forget(self::key($linkId));
    }
}

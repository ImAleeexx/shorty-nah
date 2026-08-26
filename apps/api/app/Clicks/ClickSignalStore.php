<?php

declare(strict_types=1);

namespace App\Clicks;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Holds the client-side signals a beacon reported, until the click pipeline
 * consumes them.
 *
 * The redirect responds before anything is persisted, so the beacon can arrive
 * before, during or after the click envelope is processed. Parking the signals
 * under the click identifier lets the two meet without either waiting for the
 * other.
 */
final class ClickSignalStore
{
    private const PREFIX = 'shortynah:click-signals:';

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @param  array<string, mixed>  $signals
     */
    public function put(string $clickId, array $signals): void
    {
        $this->cache->put(self::PREFIX.$clickId, $signals, ClickToken::LIFETIME_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $clickId): ?array
    {
        $signals = $this->cache->get(self::PREFIX.$clickId);

        return is_array($signals) ? $signals : null;
    }

    public function forget(string $clickId): void
    {
        $this->cache->forget(self::PREFIX.$clickId);
    }
}

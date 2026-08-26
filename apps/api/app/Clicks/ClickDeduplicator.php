<?php

declare(strict_types=1);

namespace App\Clicks;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Collapses repeated events for one visitor and link inside a short window.
 *
 * Double-fires are ordinary: a browser retries, a proxy replays, a visitor
 * double-taps. Counting each one would overstate every figure the product exists
 * to report.
 */
final class ClickDeduplicator
{
    private const PREFIX = 'shortynah:click-dedupe:';

    public const WINDOW_SECONDS = 30;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * True when this pair has already been seen inside the window.
     */
    public function isDuplicate(string $visitorHash, int $linkId): bool
    {
        $key = self::PREFIX.$linkId.':'.$visitorHash;

        // add() is atomic, so two workers processing the same pair concurrently
        // cannot both decide they are the first.
        return ! $this->cache->add($key, true, self::WINDOW_SECONDS);
    }
}

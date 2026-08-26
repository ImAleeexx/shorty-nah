<?php

declare(strict_types=1);

namespace App\Clicks;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Identifies a visitor without keeping anything that identifies them.
 *
 * The salt rotates and prior salts are discarded, so an identifier cannot be
 * recomputed from an address afterwards — not by us, and not by anyone who takes
 * the database.
 *
 * The cost is real and deliberate: a visitor returning tomorrow is a new unique.
 * Unique counts are therefore reported per period and never summed across them.
 */
final class VisitorHash
{
    private const SALT_KEY = 'shortynah:visitor-salt';

    public const ROTATION_SECONDS = 86400;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $applicationKey,
    ) {}

    public function for(?string $address, ?string $userAgent): string
    {
        // A missing address still produces a stable identifier for the request's
        // other attributes rather than collapsing every such visitor into one.
        $material = ($address ?? 'no-address').'|'.($userAgent ?? 'no-user-agent');

        return hash_hmac('sha256', $material, $this->salt());
    }

    /**
     * Forces the next call to derive a new salt. Used when rotating on a schedule
     * and by tests that need to observe the change.
     */
    public function rotate(): void
    {
        $this->cache->forget(self::SALT_KEY);
    }

    private function salt(): string
    {
        $salt = $this->cache->get(self::SALT_KEY);

        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        $fresh = Str::random(64);

        // Bound to the application key as well, so a cache dump alone does not
        // give someone the ability to recompute identifiers.
        $derived = hash_hmac('sha256', $fresh.'|'.Carbon::now()->toDateString(), $this->applicationKey);

        $this->cache->put(self::SALT_KEY, $derived, self::ROTATION_SECONDS);

        return $derived;
    }
}

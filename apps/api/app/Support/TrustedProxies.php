<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Resolves the peers whose forwarding headers may be believed.
 *
 * A wildcard is refused rather than merely discouraged: trusting every peer
 * means any client can set its own apparent address, which silently defeats
 * redirect rate limiting and makes every geographic figure forgeable.
 */
final class TrustedProxies
{
    public const WILDCARD_REJECTED = 'TRUSTED_PROXIES must name the edge network, not a wildcard.';

    /**
     * @return list<string>
     */
    public static function configured(): array
    {
        /** @var mixed $configured */
        $configured = config('shortynah.trusted_proxies');

        return self::parse(is_string($configured) ? $configured : '');
    }

    /**
     * @return list<string>
     */
    public static function parse(string $value): array
    {
        // Commas or whitespace: the application reads a comma list, the edge
        // reads the same ranges space-separated, and one value feeds both.
        $entries = array_values(array_filter(
            preg_split('/[\s,]+/', $value) ?: [],
            static fn (string $entry): bool => $entry !== '',
        ));

        foreach ($entries as $entry) {
            if ($entry === '*' || $entry === '**') {
                throw new RuntimeException(self::WILDCARD_REJECTED);
            }
        }

        return $entries;
    }
}

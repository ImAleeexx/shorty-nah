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
        $entries = array_values(array_filter(
            array_map(static fn (string $entry): string => trim($entry), explode(',', $value)),
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

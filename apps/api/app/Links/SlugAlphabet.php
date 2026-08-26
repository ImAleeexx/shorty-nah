<?php

declare(strict_types=1);

namespace App\Links;

/**
 * Two character sets, because generated and operator-chosen slugs have different
 * jobs.
 *
 * GENERATED excludes `0`, `O`, `I` and `l`. A machine-produced slug is read off a
 * screen, typed into a phone and read aloud, and those four are the pairs that
 * get transcribed wrongly. Nobody chose them, so nobody misses them.
 *
 * CUSTOM keeps them. An operator asking for `launch` or `blog` knows exactly what
 * they typed, and a set that rejected every word containing an `l` would be
 * useless. It stays URL-safe: letters, digits, hyphen and underscore.
 */
final class SlugAlphabet
{
    public const GENERATED = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public const CUSTOM_DESCRIPTION = 'letters, digits, hyphen and underscore';

    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 64;

    public static function generatedSize(): int
    {
        return strlen(self::GENERATED);
    }

    /**
     * Whether a slug could have come out of the generator.
     */
    public static function isGeneratable(string $slug): bool
    {
        if ($slug === '') {
            return false;
        }

        return strspn($slug, self::GENERATED) === strlen($slug);
    }

    /**
     * Whether an operator may choose this slug.
     */
    public static function permitsCustom(string $slug): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $slug) === 1;
    }
}

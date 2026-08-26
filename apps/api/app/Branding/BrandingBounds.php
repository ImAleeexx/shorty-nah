<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * What an operator may choose.
 *
 * Bounded on purpose. An unbounded radius reproduces the squircle look this
 * design rejects; an unbounded typeface field lets Inter back in; an unbounded
 * accent produces an unreadable interface. One accent hue, a clamped radius and a
 * curated list is the whole degree of freedom, and it is what lets every derived
 * state be generated rather than hand-tuned.
 */
final class BrandingBounds
{
    public const RADIUS_MIN = 4;

    public const RADIUS_MAX = 14;

    /**
     * Faces the instance ships, self-hosted.
     *
     * Inter, Roboto, Helvetica and Open Sans are absent deliberately, and every
     * option here renders `0`/`O` and `1`/`l` distinguishably — a slug is
     * transcribed by hand.
     *
     * @var array<string, string>
     */
    private const TYPEFACES = [
        'geist' => 'Geist',
        'geist-mono' => 'Geist Mono',
        'instrument-serif' => 'Instrument Serif',
    ];

    /** Numeric OKLCH only: the form the interface parses and re-validates. */
    private const ACCENT_PATTERN = '/^oklch\(\s*(?:0|1|0?\.\d+)\s+(?:0|0?\.\d+)\s+(?:\d{1,3}(?:\.\d+)?)\s*\)$/';

    /**
     * @return array<string, string>
     */
    public static function typefaces(): array
    {
        return self::TYPEFACES;
    }

    public static function permitsTypeface(string $typeface): bool
    {
        return array_key_exists($typeface, self::TYPEFACES);
    }

    public static function permitsAccent(string $accent): bool
    {
        if (preg_match(self::ACCENT_PATTERN, trim($accent)) !== 1) {
            return false;
        }

        $parts = self::accentComponents($accent);

        if ($parts === null) {
            return false;
        }

        [$lightness, $chroma, $hue] = $parts;

        return $lightness >= 0.0 && $lightness <= 1.0
            && $chroma >= 0.0 && $chroma <= 0.4
            && $hue >= 0.0 && $hue <= 360.0;
    }

    public static function permitsRadius(int $radius): bool
    {
        return $radius >= self::RADIUS_MIN && $radius <= self::RADIUS_MAX;
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function accentComponents(string $accent): ?array
    {
        if (preg_match('/^oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\)$/', trim($accent), $matches) !== 1) {
            return null;
        }

        return [(float) $matches[1], (float) $matches[2], (float) $matches[3]];
    }
}

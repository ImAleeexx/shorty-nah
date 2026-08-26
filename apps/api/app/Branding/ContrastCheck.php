<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * Whether an accent stays readable in both colour modes.
 *
 * Checked server-side as well as in the editor, because the API is the boundary
 * that matters: a token client can set an accent without ever loading the
 * interface that would have warned about it.
 *
 * The same OKLCH to sRGB conversion the interface uses. Duplicated across the two
 * languages on purpose — a shared service call would put a network round trip
 * inside a colour picker's drag.
 */
final class ContrastCheck
{
    /** WCAG AA for large text and for the meaningful edge of a control. */
    public const MINIMUM = 3.0;

    /** The canvases the accent is judged against, from the token layer. */
    private const LIGHT_CANVAS = [0.985, 0.002, 90.0];

    private const DARK_CANVAS = [0.165, 0.003, 90.0];

    /**
     * @return array{passes: bool, light: float, dark: float, warning: string|null}
     */
    public static function assess(string $accent): array
    {
        $components = BrandingBounds::accentComponents($accent);

        if ($components === null) {
            return ['passes' => false, 'light' => 0.0, 'dark' => 0.0, 'warning' => 'That accent could not be read.'];
        }

        $light = self::ratio($components, self::LIGHT_CANVAS);
        $dark = self::ratio($components, self::DARK_CANVAS);

        $failing = [];

        if ($light < self::MINIMUM) {
            $failing[] = 'light';
        }

        if ($dark < self::MINIMUM) {
            $failing[] = 'dark';
        }

        return [
            'passes' => $failing === [],
            'light' => round($light, 2),
            'dark' => round($dark, 2),
            'warning' => $failing === [] ? null : sprintf(
                'This accent falls below the readable minimum in %s mode. Adjust its lightness.',
                implode(' and ', $failing),
            ),
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $first
     * @param  array{0: float, 1: float, 2: float}  $second
     */
    private static function ratio(array $first, array $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        $lighter = max($a, $b);
        $darker = min($a, $b);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Relative luminance from linear sRGB, before the transfer function — which is
     * what WCAG means. Applying it to gamma-encoded values overstates contrast on
     * dark colours.
     *
     * @param  array{0: float, 1: float, 2: float}  $oklch
     */
    private static function luminance(array $oklch): float
    {
        [$l, $c, $h] = $oklch;

        $radians = $h * M_PI / 180;
        $a = $c * cos($radians);
        $b = $c * sin($radians);

        $lp = $l + 0.3963377774 * $a + 0.2158037573 * $b;
        $mp = $l - 0.1055613458 * $a - 0.0638541728 * $b;
        $sp = $l - 0.0894841775 * $a - 1.2914855480 * $b;

        $lc = $lp ** 3;
        $mc = $mp ** 3;
        $sc = $sp ** 3;

        $red = 4.0767416621 * $lc - 3.3077115913 * $mc + 0.2309699292 * $sc;
        $green = -1.2684380046 * $lc + 2.6097574011 * $mc - 0.3413193965 * $sc;
        $blue = -0.0041960863 * $lc - 0.7034186147 * $mc + 1.7076147010 * $sc;

        return 0.2126 * self::clamp($red) + 0.7152 * self::clamp($green) + 0.0722 * self::clamp($blue);
    }

    private static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}

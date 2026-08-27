<?php

declare(strict_types=1);

namespace App\Branding;

use App\Settings\SettingsStore;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Renders a link's short URL as a scannable code in the instance's own colours.
 *
 * Bacon encodes; this draws. The library ships an Imagick back end and an SVG
 * one, and the image this runs on has GD — but drawing both formats from the one
 * matrix is worth more than either back end anyway, because it is the only way
 * the PNG and the SVG are guaranteed to be the same code with the same quiet
 * zone and the same colour.
 */
final class QrRenderer
{
    /**
     * Higher than the 3:1 the interface enforces for text.
     *
     * A scanner is not a reader: it thresholds an image captured under whatever
     * light the visitor happens to be standing in, at an angle, on a camera that
     * may be cheap. An accent that is merely legible on a screen is not
     * necessarily a code that resolves on a phone, and a code that fails to scan
     * fails silently — the visitor simply gives up.
     */
    public const MINIMUM_CONTRAST = 4.5;

    /** Quiet zone in modules. Four is what the specification requires. */
    private const QUIET_ZONE = 4;

    private const MODULE_PIXELS = 10;

    /** The ink the fallback uses, matching the interface's own. */
    private const INK = [26, 26, 24];

    public function __construct(private readonly SettingsStore $settings) {}

    public function render(string $url, string $format): QrCode
    {
        [$rgb, $usedFallback] = $this->foreground();

        $matrix = Encoder::encode($url, ErrorCorrectionLevel::M())->getMatrix();

        $modules = [];

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            $row = [];

            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $row[] = $matrix->get($x, $y) === 1;
            }

            $modules[] = $row;
        }

        $hex = sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);

        return $format === 'svg'
            ? new QrCode($this->svg($modules, $hex), 'image/svg+xml', 'svg', $usedFallback, $hex)
            : new QrCode($this->png($modules, $rgb), 'image/png', 'png', $usedFallback, $hex);
    }

    /**
     * @return array{0: array{int, int, int}, 1: bool}
     */
    private function foreground(): array
    {
        $accent = $this->settings->string('branding.accent') ?? '';
        $components = BrandingBounds::accentComponents($accent);

        if ($components === null) {
            return [self::INK, true];
        }

        // Judged against white rather than the light canvas: a code is printed,
        // pasted onto a slide and shown on a screen, and white is the surface it
        // will actually sit on more often than the interface's own.
        if (ContrastCheck::ratioAgainstWhite($components) < self::MINIMUM_CONTRAST) {
            return [self::INK, true];
        }

        return [ContrastCheck::toRgb($components), false];
    }

    /**
     * @param  list<list<bool>>  $modules
     */
    private function svg(array $modules, string $hex): string
    {
        $size = count($modules) + self::QUIET_ZONE * 2;

        $paths = [];

        foreach ($modules as $y => $row) {
            foreach ($row as $x => $filled) {
                if ($filled) {
                    $paths[] = sprintf('M%d %dh1v1h-1z', $x + self::QUIET_ZONE, $y + self::QUIET_ZONE);
                }
            }
        }

        // One path for every module rather than one rect each: a code is a few
        // hundred modules, and a few hundred elements is a document a browser has
        // to lay out.
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" shape-rendering="crispEdges" role="img">'
            .'<rect width="%d" height="%d" fill="#ffffff"/>'
            .'<path d="%s" fill="%s"/>'
            .'</svg>',
            $size,
            $size,
            $size * self::MODULE_PIXELS,
            $size * self::MODULE_PIXELS,
            $size,
            $size,
            implode('', $paths),
            $hex,
        );
    }

    /**
     * @param  list<list<bool>>  $modules
     * @param  array{int, int, int}  $rgb
     */
    private function png(array $modules, array $rgb): string
    {
        $size = (count($modules) + self::QUIET_ZONE * 2) * self::MODULE_PIXELS;

        $image = imagecreatetruecolor($size, $size);

        // Clamped rather than trusted: the colour arrives from an operator's
        // accent by way of a colour-space conversion, and GD refuses anything
        // outside the byte range rather than clipping it.
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $ink = (int) imagecolorallocate(
            $image,
            max(0, min(255, $rgb[0])),
            max(0, min(255, $rgb[1])),
            max(0, min(255, $rgb[2])),
        );

        imagefilledrectangle($image, 0, 0, $size, $size, $white);

        foreach ($modules as $y => $row) {
            foreach ($row as $x => $filled) {
                if (! $filled) {
                    continue;
                }

                $left = ($x + self::QUIET_ZONE) * self::MODULE_PIXELS;
                $top = ($y + self::QUIET_ZONE) * self::MODULE_PIXELS;

                imagefilledrectangle(
                    $image,
                    $left,
                    $top,
                    $left + self::MODULE_PIXELS - 1,
                    $top + self::MODULE_PIXELS - 1,
                    $ink,
                );
            }
        }

        ob_start();
        imagepng($image);
        $body = (string) ob_get_clean();

        imagedestroy($image);

        return $body;
    }
}

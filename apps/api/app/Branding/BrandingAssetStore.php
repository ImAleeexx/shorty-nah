<?php

declare(strict_types=1);

namespace App\Branding;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Validates and stores an uploaded branding asset.
 *
 * Format is decided by decoding the file, never by its extension or its declared
 * content type — both are attacker-supplied. SVG is refused outright rather than
 * sanitised: it can carry script, it would be served from the interface's own
 * origin, and every SVG sanitiser is a bypass-of-the-month target.
 *
 * Everything accepted is re-encoded, which strips metadata and anything appended
 * past the image data, and stored under a generated name so a client filename can
 * never become a path.
 */
final class BrandingAssetStore
{
    public const MAX_BYTES = 2_097_152;

    public const MAX_DIMENSION = 4096;

    /** Formats a browser renders and GD can decode and re-encode. */
    private const PERMITTED = [
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpeg',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    public function __construct(private readonly Filesystem $disk) {}

    /**
     * @return string The path the interface should reference.
     */
    public function store(UploadedFile $file, string $kind): string
    {
        if (! in_array($kind, ['logo', 'wordmark', 'favicon'], true)) {
            throw new BrandingException('Unknown branding asset.');
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new BrandingException('That upload could not be read.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new BrandingException(sprintf(
                'A branding asset must be %d KB or smaller.',
                (int) (self::MAX_BYTES / 1024),
            ));
        }

        $this->refuseScriptableFormats($path);

        // Dimensions are read from the header before any pixels are decoded, so a
        // decompression bomb is refused rather than allocated.
        $info = @getimagesize($path);

        if ($info === false) {
            throw new BrandingException('That file is not an image.');
        }

        [$width, $height, $type] = $info;

        if (! isset(self::PERMITTED[$type])) {
            throw new BrandingException(
                'A branding asset must be a PNG, JPEG, WebP or GIF image.'
            );
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new BrandingException(sprintf(
                'A branding asset must be at most %dx%d pixels.',
                self::MAX_DIMENSION,
                self::MAX_DIMENSION,
            ));
        }

        $encoded = $this->reencode($path, $type);

        // A generated name: the client's filename is never used, so it cannot
        // traverse or collide.
        $stored = sprintf('branding/%s-%s.webp', $kind, Str::lower((string) Str::ulid()));

        $this->disk->put($stored, $encoded, ['visibility' => 'public']);

        return '/storage/'.$stored;
    }

    public function forget(?string $path): void
    {
        if ($path === null || ! str_starts_with($path, '/storage/branding/')) {
            return;
        }

        $this->disk->delete(substr($path, strlen('/storage/')));
    }

    /**
     * SVG and anything else that can execute or fetch. Checked against the
     * file's contents rather than its name, because the name proves nothing.
     */
    private function refuseScriptableFormats(string $path): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        $normalised = mb_strtolower($head);

        foreach (['<svg', '<?xml', '<!doctype html', '<html', '<script'] as $marker) {
            if (str_contains($normalised, $marker)) {
                throw new BrandingException(
                    'SVG and markup files are not accepted. Use a PNG, JPEG, WebP or GIF image.'
                );
            }
        }
    }

    /**
     * Re-encodes to WebP. Nothing from the original file survives except its
     * pixels, which is the point: metadata, trailing payloads and format quirks
     * are all discarded.
     */
    private function reencode(string $path, int $type): string
    {
        $image = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            default => false,
        };

        if ($image === false) {
            throw new BrandingException('That image could not be decoded.');
        }

        // GIFs and 8-bit PNGs are palette images, which imagewebp() refuses. This
        // is not a GIF-only concern: PNG-8 is a common export from design tools,
        // and without this every one of them would fail.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $written = imagewebp($image, null, 88);
        $encoded = (string) ob_get_clean();

        imagedestroy($image);

        if (! $written || $encoded === '') {
            throw new BrandingException('That image could not be re-encoded.');
        }

        return $encoded;
    }
}

<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * A rendered code and how it was rendered.
 *
 * `usedFallback` is part of the result rather than a log line: an operator who
 * chose an accent and got a black code is owed the reason, and the interface
 * cannot tell by looking at the bytes.
 */
final class QrCode
{
    public function __construct(
        public readonly string $body,
        public readonly string $contentType,
        public readonly string $extension,
        public readonly bool $usedFallback,
        public readonly string $foreground,
    ) {}
}

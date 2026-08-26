<?php

declare(strict_types=1);

namespace App\Clicks;

final class GeoResult
{
    public function __construct(
        public readonly string $countryCode = '',
        public readonly string $region = '',
        public readonly string $city = '',
        public readonly int $asn = 0,
        public readonly string $organisation = '',
    ) {}

    public static function unknown(): self
    {
        return new self;
    }

    public function isKnown(): bool
    {
        return $this->countryCode !== '';
    }
}

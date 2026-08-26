<?php

declare(strict_types=1);

namespace App\Clicks;

final class RedeemedClick
{
    public function __construct(
        public readonly int $linkId,
        public readonly string $clickId,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Clicks;

final class ClientProfile
{
    public function __construct(
        public readonly string $deviceType = '',
        public readonly string $operatingSystem = '',
        public readonly string $browser = '',
        public readonly bool $isBot = false,
        public readonly string $botName = '',
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Setup;

final class DependencyStatus
{
    public function __construct(
        public readonly string $name,
        public readonly bool $healthy,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return array{name: string, healthy: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'healthy' => $this->healthy, 'reason' => $this->reason];
    }
}

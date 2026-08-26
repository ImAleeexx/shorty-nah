<?php

declare(strict_types=1);

namespace App\Domains;

final class DomainVerificationResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $failure,
    ) {}
}

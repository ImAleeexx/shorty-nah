<?php

declare(strict_types=1);

namespace App\Links;

use App\Enums\RuleKind;

/**
 * A rule as the redirect path sees it: no model, no relations, nothing that
 * would need loading. This is what travels inside the cache entry.
 */
final class RoutingRule
{
    public function __construct(
        public readonly RuleKind $kind,
        public readonly string $value,
        public readonly string $destination,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'value' => $this->value,
            'destination' => $this->destination,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $kind = RuleKind::tryFrom(is_string($payload['kind'] ?? null) ? $payload['kind'] : '');

        if ($kind === null) {
            return null;
        }

        return new self(
            kind: $kind,
            value: is_string($payload['value'] ?? null) ? $payload['value'] : '',
            destination: is_string($payload['destination'] ?? null) ? $payload['destination'] : '',
        );
    }
}

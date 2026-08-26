<?php

declare(strict_types=1);

namespace App\Links;

use App\Enums\RedirectMode;
use Illuminate\Support\Carbon;

/**
 * A cache entry, deliberately self-contained.
 *
 * Everything needed to decide whether to redirect and where is here, so a cache
 * hit answers without a second lookup of any kind. The click limit is the one
 * exception, because it is counted in Redis rather than stored.
 */
final class ResolvedLink
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $destination,
        public readonly RedirectMode $mode,
        public readonly bool $requiresPassword,
        public readonly bool $disabled,
        public readonly ?int $expiresAtTimestamp,
        public readonly ?int $maxClicks,
        public readonly int $persistedClicks,
        public readonly ?string $referrerPolicy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->publicId,
            'destination' => $this->destination,
            'mode' => $this->mode->value,
            'requires_password' => $this->requiresPassword,
            'disabled' => $this->disabled,
            'expires_at' => $this->expiresAtTimestamp,
            'max_clicks' => $this->maxClicks,
            'persisted_clicks' => $this->persistedClicks,
            'referrer_policy' => $this->referrerPolicy,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: is_int($payload['id'] ?? null) ? $payload['id'] : 0,
            publicId: is_string($payload['public_id'] ?? null) ? $payload['public_id'] : '',
            destination: is_string($payload['destination'] ?? null) ? $payload['destination'] : '',
            mode: RedirectMode::tryFrom(is_string($payload['mode'] ?? null) ? $payload['mode'] : '') ?? RedirectMode::Direct,
            requiresPassword: (bool) ($payload['requires_password'] ?? false),
            disabled: (bool) ($payload['disabled'] ?? false),
            expiresAtTimestamp: is_int($payload['expires_at'] ?? null) ? $payload['expires_at'] : null,
            maxClicks: is_int($payload['max_clicks'] ?? null) ? $payload['max_clicks'] : null,
            persistedClicks: is_int($payload['persisted_clicks'] ?? null) ? $payload['persisted_clicks'] : 0,
            referrerPolicy: is_string($payload['referrer_policy'] ?? null) ? $payload['referrer_policy'] : null,
        );
    }

    public function isExpired(): bool
    {
        return $this->expiresAtTimestamp !== null
            && $this->expiresAtTimestamp <= Carbon::now()->getTimestamp();
    }

    /**
     * The higher of the live counter and the count persisted when this entry was
     * built.
     *
     * Redis is where clicks are counted, and Redis can be flushed. Without the
     * persisted floor, losing the counter would quietly reopen every link that
     * had already reached its limit.
     */
    public function hasReachedLimit(int $clicksSoFar): bool
    {
        if ($this->maxClicks === null) {
            return false;
        }

        return max($clicksSoFar, $this->persistedClicks) >= $this->maxClicks;
    }
}

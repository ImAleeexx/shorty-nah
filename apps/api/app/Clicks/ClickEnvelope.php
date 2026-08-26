<?php

declare(strict_types=1);

namespace App\Clicks;

/**
 * What the redirect path records and hands to the pipeline.
 *
 * Deliberately raw: the redirect does no enrichment at all, so the only work on
 * the hot path is building this and pushing it. The address is present here
 * because enrichment needs it, and is discarded before anything is persisted.
 */
final class ClickEnvelope
{
    public function __construct(
        public readonly string $clickId,
        public readonly int $linkId,
        public readonly int $domainId,
        public readonly string $occurredAt,
        public readonly ?string $address,
        public readonly ?string $userAgent,
        public readonly ?string $referrer,
        public readonly string $redirectMode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'click_id' => $this->clickId,
            'link_id' => $this->linkId,
            'domain_id' => $this->domainId,
            'occurred_at' => $this->occurredAt,
            'address' => $this->address,
            'user_agent' => $this->userAgent,
            'referrer' => $this->referrer,
            'redirect_mode' => $this->redirectMode,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            clickId: is_string($payload['click_id'] ?? null) ? $payload['click_id'] : '',
            linkId: is_int($payload['link_id'] ?? null) ? $payload['link_id'] : 0,
            domainId: is_int($payload['domain_id'] ?? null) ? $payload['domain_id'] : 0,
            occurredAt: is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : '',
            address: is_string($payload['address'] ?? null) ? $payload['address'] : null,
            userAgent: is_string($payload['user_agent'] ?? null) ? $payload['user_agent'] : null,
            referrer: is_string($payload['referrer'] ?? null) ? $payload['referrer'] : null,
            redirectMode: is_string($payload['redirect_mode'] ?? null) ? $payload['redirect_mode'] : 'direct',
        );
    }
}

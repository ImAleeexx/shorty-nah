<?php

declare(strict_types=1);

namespace App\Clicks;

/**
 * What the redirect path records and hands to the pipeline.
 *
 * The address used to travel on this envelope so that enrichment could resolve
 * it. It no longer does: country rules have to be evaluated before a visitor is
 * sent anywhere, so geography is resolved during the request and the resolved
 * values travel instead. A raw address now exists only for the life of the
 * request that carried it, and never reaches Redis.
 *
 * `address` survives as a legacy field for one reason: a deploy with a non-empty
 * queue has envelopes on it in the old shape, and draining them must still work.
 * Nothing writes it any more.
 */
final class ClickEnvelope
{
    public function __construct(
        public readonly string $clickId,
        public readonly int $linkId,
        public readonly int $domainId,
        public readonly string $occurredAt,
        public readonly ?string $userAgent,
        public readonly ?string $referrer,
        public readonly string $redirectMode,
        /** Empty for an ordinary click, 'qr' for a scan. */
        public readonly string $source = '',
        public readonly ?GeoResult $geo = null,
        public readonly ?string $visitorHash = null,
        public readonly ?string $address = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'click_id' => $this->clickId,
            'link_id' => $this->linkId,
            'domain_id' => $this->domainId,
            'occurred_at' => $this->occurredAt,
            'user_agent' => $this->userAgent,
            'referrer' => $this->referrer,
            'redirect_mode' => $this->redirectMode,
            'source' => $this->source,
        ];

        if ($this->geo instanceof GeoResult) {
            $payload += [
                'country_code' => $this->geo->countryCode,
                'region' => $this->geo->region,
                'city' => $this->geo->city,
                'asn' => $this->geo->asn,
                'organisation' => $this->geo->organisation,
            ];
        }

        if ($this->visitorHash !== null) {
            $payload['visitor_hash'] = $this->visitorHash;
        }

        // Absent rather than null: a key named address in a queue payload invites
        // someone to start filling it in.
        if ($this->address !== null) {
            $payload['address'] = $this->address;
        }

        return $payload;
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
            userAgent: is_string($payload['user_agent'] ?? null) ? $payload['user_agent'] : null,
            referrer: is_string($payload['referrer'] ?? null) ? $payload['referrer'] : null,
            redirectMode: is_string($payload['redirect_mode'] ?? null) ? $payload['redirect_mode'] : 'direct',
            source: is_string($payload['source'] ?? null) ? $payload['source'] : '',
            geo: self::geoFrom($payload),
            visitorHash: is_string($payload['visitor_hash'] ?? null) ? $payload['visitor_hash'] : null,
            address: is_string($payload['address'] ?? null) ? $payload['address'] : null,
        );
    }

    /**
     * Absent geography and resolved-as-unknown geography are different things.
     * The first means an old envelope that enrichment must still resolve; the
     * second means the lookup ran and found nothing, and must not run again.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function geoFrom(array $payload): ?GeoResult
    {
        if (! array_key_exists('country_code', $payload)) {
            return null;
        }

        return new GeoResult(
            countryCode: is_string($payload['country_code']) ? $payload['country_code'] : '',
            region: is_string($payload['region'] ?? null) ? $payload['region'] : '',
            city: is_string($payload['city'] ?? null) ? $payload['city'] : '',
            asn: is_int($payload['asn'] ?? null) ? $payload['asn'] : 0,
            organisation: is_string($payload['organisation'] ?? null) ? $payload['organisation'] : '',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Clicks;

/**
 * A click ready to be written to the event store.
 *
 * The address is absent by construction: there is no field to put it in, so the
 * privacy guarantee cannot be broken by forgetting to strip it.
 */
final class EnrichedClick
{
    /**
     * @param  array<string, mixed>  $signals
     */
    public function __construct(
        private readonly ClickEnvelope $envelope,
        private readonly string $visitorHash,
        private readonly GeoResult $geo,
        private readonly ClientProfile $client,
        private readonly bool $isAutomated,
        private readonly string $automatedReason,
        private readonly bool $isDuplicate,
        private readonly array $signals = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'click_id' => $this->envelope->clickId,
            'link_id' => $this->envelope->linkId,
            'domain_id' => $this->envelope->domainId,
            'occurred_at' => $this->envelope->occurredAt,
            'visitor_hash' => $this->visitorHash,

            'is_automated' => $this->isAutomated ? 1 : 0,
            'automated_reason' => $this->automatedReason,
            'is_duplicate' => $this->isDuplicate ? 1 : 0,

            'country_code' => $this->geo->countryCode,
            'region' => $this->geo->region,
            'city' => $this->geo->city,
            'asn' => $this->geo->asn,
            'as_organisation' => $this->geo->organisation,

            'device_type' => $this->client->deviceType,
            'operating_system' => $this->client->operatingSystem,
            'browser' => $this->client->browser,

            'source' => $this->envelope->source,

            'referrer_host' => $this->referrerHost(),
            'redirect_mode' => $this->envelope->redirectMode,

            'viewport_width' => $this->number('viewport_width'),
            'viewport_height' => $this->number('viewport_height'),
            'screen_width' => $this->number('screen_width'),
            'screen_height' => $this->number('screen_height'),
            'device_pixel_ratio' => $this->float('device_pixel_ratio'),
            'timezone' => $this->text('timezone'),
            'language' => $this->text('language'),
            'color_scheme' => $this->text('color_scheme'),
            'connection_type' => $this->text('connection_type'),
            'dwell_ms' => $this->number('dwell_ms'),
        ];
    }

    /**
     * What a subscriber is told about a click.
     *
     * A deliberate subset of the row, not the row itself: no visitor hash, which
     * is an identifier this instance chose not to be able to reverse and has no
     * business handing out, and no address, which does not exist by this point
     * anyway.
     *
     * @return array<string, mixed>
     */
    public function toWebhookPayload(): array
    {
        return [
            'click_id' => $this->envelope->clickId,
            'link_id' => $this->envelope->linkId,
            'domain_id' => $this->envelope->domainId,
            'occurred_at' => $this->envelope->occurredAt,
            'redirect_mode' => $this->envelope->redirectMode,
            'source' => $this->envelope->source,
            'country_code' => $this->geo->countryCode,
            'region' => $this->geo->region,
            'city' => $this->geo->city,
            'asn' => $this->geo->asn,
            'device_type' => $this->client->deviceType,
            'operating_system' => $this->client->operatingSystem,
            'browser' => $this->client->browser,
            'referrer_host' => $this->referrerHost(),
        ];
    }

    public function isCounted(): bool
    {
        return ! $this->isAutomated && ! $this->isDuplicate;
    }

    /**
     * Only the referrer's host is kept. A full referrer URL can carry a search
     * query or a session token in its path, which is not ours to store.
     */
    private function referrerHost(): string
    {
        if ($this->envelope->referrer === null) {
            return '';
        }

        $host = parse_url($this->envelope->referrer, PHP_URL_HOST);

        return is_string($host) ? mb_strtolower(mb_substr($host, 0, 253)) : '';
    }

    private function number(string $key): int
    {
        $value = $this->signals[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function float(string $key): float
    {
        $value = $this->signals[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function text(string $key): string
    {
        $value = $this->signals[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}

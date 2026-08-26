<?php

declare(strict_types=1);

namespace App\Clicks;

use App\Settings\SettingsStore;

/**
 * Turns a raw envelope into a row.
 *
 * The order is deliberate and is the point of the class: cheap rejections happen
 * before expensive work, so traffic that will not be counted never pays for a geo
 * lookup.
 *
 *   1. user agent — string matching, no I/O
 *   2. geography and network — file-backed lookup, only if still interesting
 *   3. datacenter check — needs the network from step 2
 *   4. visitor hash — cheap, but pointless before we know we are keeping the row
 *   5. deduplication — needs the hash
 */
final class ClickEnricher
{
    public function __construct(
        private readonly UserAgentParser $userAgents,
        private readonly GeoResolver $geo,
        private readonly VisitorHash $visitors,
        private readonly ClickDeduplicator $deduplicator,
        private readonly ClickSignalStore $signals,
        private readonly SettingsStore $settings,
    ) {}

    public function enrich(ClickEnvelope $envelope): EnrichedClick
    {
        $filtering = $this->settings->boolean('analytics.bot_filtering');

        $client = $this->userAgents->parse($envelope->userAgent);

        $automated = false;
        $reason = '';

        if ($filtering && $client->isBot) {
            $automated = true;
            $reason = $client->botName;
        }

        // A client already known to be automated does not need geography: this is
        // the whole reason the order above is fixed.
        $geo = $automated ? GeoResult::unknown() : $this->geo->lookup($envelope->address);

        if (! $automated && $filtering && DatacenterNetworks::isDatacenter($geo->asn)) {
            $automated = true;
            $reason = 'datacenter:'.(DatacenterNetworks::organisationFor($geo->asn) ?? (string) $geo->asn);
        }

        $visitorHash = $this->visitors->for($envelope->address, $envelope->userAgent);

        // Automated traffic is not deduplicated: it is already excluded from
        // counts, and spending a cache write on it achieves nothing.
        $duplicate = ! $automated && $this->deduplicator->isDuplicate($visitorHash, $envelope->linkId);

        return new EnrichedClick(
            envelope: $envelope,
            visitorHash: $visitorHash,
            geo: $geo,
            client: $client,
            isAutomated: $automated,
            automatedReason: $reason,
            isDuplicate: $duplicate,
            signals: $this->signals->get($envelope->clickId) ?? [],
        );
    }

    /**
     * @param  list<ClickEnvelope>  $envelopes
     * @return list<EnrichedClick>
     */
    public function enrichAll(array $envelopes): array
    {
        return array_map($this->enrich(...), $envelopes);
    }
}

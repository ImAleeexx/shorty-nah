<?php

declare(strict_types=1);

namespace App\Domains;

use App\Models\Domain;
use App\Settings\SettingsStore;
use App\Support\NetworkAddress;
use Illuminate\Support\Carbon;

/**
 * Confirms a registered domain actually points at this instance.
 *
 * Verification compares the addresses the host resolves to against the
 * instance's configured public address. DNS is the whole check: there is no
 * outbound HTTP request to the operator's host, which would be an SSRF surface
 * pointed at an attacker-chosen name.
 */
final class DomainVerifier
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly SettingsStore $settings,
        private readonly DomainRegistry $registry,
    ) {}

    public function verify(Domain $domain): DomainVerificationResult
    {
        $expected = $this->expectedAddresses();

        if ($expected === []) {
            return $this->record($domain, false, 'The instance public address is not configured.');
        }

        $resolved = $this->dns->addressesFor($domain->host);

        if ($resolved === []) {
            return $this->record($domain, false, 'The host does not resolve.');
        }

        foreach ($resolved as $address) {
            // A host resolving inside the operator's network is refused even if
            // it matches: serving it would mean issuing a certificate for a name
            // that cannot be reached publicly.
            if (! NetworkAddress::isPubliclyRoutable($address)) {
                return $this->record($domain, false, 'The host resolves to a non-public address.');
            }
        }

        $matches = array_intersect($resolved, $expected) !== [];

        if (! $matches) {
            return $this->record($domain, false, 'The host does not resolve to this instance.');
        }

        return $this->record($domain, true, null);
    }

    /**
     * @return list<string>
     */
    private function expectedAddresses(): array
    {
        $configured = $this->settings->string('domains.instance_addresses');

        if ($configured === null || trim($configured) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $a): string => trim($a), explode(',', $configured)),
            static fn (string $a): bool => $a !== '',
        ));
    }

    private function record(Domain $domain, bool $verified, ?string $failure): DomainVerificationResult
    {
        $domain->forceFill([
            'verified_at' => $verified ? ($domain->verified_at ?? Carbon::now()) : null,
            'last_checked_at' => Carbon::now(),
            'last_failure' => $failure,
        ])->save();

        // The edge reads the verified set from cache, so a change must invalidate
        // it or a newly verified domain would keep being refused a certificate.
        $this->registry->flush();

        return new DomainVerificationResult($verified, $failure);
    }
}

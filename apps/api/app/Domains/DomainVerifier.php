<?php

declare(strict_types=1);

namespace App\Domains;

use App\Models\Domain;
use Illuminate\Support\Carbon;

/**
 * Confirms the operator controls a registered domain.
 *
 * Control is proven by a TXT record carrying the domain's token, read from DNS.
 * Where the host resolves is deliberately not consulted: a host fronted by a
 * CDN resolves to the CDN's addresses and could never match this instance's.
 * Nothing is fetched from the operator's host either, which would be an SSRF
 * surface pointed at an attacker-chosen name.
 */
final class DomainVerifier
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly DomainRegistry $registry,
    ) {}

    public function verify(Domain $domain): DomainVerificationResult
    {
        $name = $domain->verificationRecordName();
        $records = array_map(trim(...), $this->dns->txtRecordsFor($name));

        if ($records === []) {
            return $this->record($domain, false, "No verification record was found at {$name}.");
        }

        if (! in_array($domain->verificationRecordValue(), $records, true)) {
            return $this->record($domain, false, "The verification record at {$name} does not carry this domain's token.");
        }

        return $this->record($domain, true, null);
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

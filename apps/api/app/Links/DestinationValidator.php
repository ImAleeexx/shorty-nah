<?php

declare(strict_types=1);

namespace App\Links;

use App\Domains\DnsResolver;
use App\Domains\DomainRegistry;
use App\Models\Domain;
use App\Settings\SettingsStore;
use App\Support\NetworkAddress;

/**
 * Decides whether a destination may be stored.
 *
 * Four separate concerns, in increasing cost order so a cheap rejection never
 * pays for a DNS lookup:
 *
 *   1. the scheme is one a browser will navigate to,
 *   2. it does not point back at this instance,
 *   3. it is not on the operator's blocklist,
 *   4. it does not resolve into a network the public cannot reach.
 */
final class DestinationValidator
{
    /**
     * @var list<string>
     */
    private const PERMITTED_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly DomainRegistry $domains,
        private readonly DnsResolver $dns,
    ) {}

    /**
     * @return string The normalised destination.
     *
     * @throws LinkException
     */
    public function validate(string $destination): string
    {
        $destination = trim($destination);

        if ($destination === '') {
            throw new LinkException('A destination is required.');
        }

        $parts = parse_url($destination);

        if ($parts === false) {
            throw new LinkException('The destination must be an absolute URL including a scheme and host.');
        }

        // The scheme is judged before the host is required. javascript:, data:
        // and file: URLs carry no host, so demanding one first would refuse them
        // for the wrong reason and tell the caller something unhelpful.
        $scheme = isset($parts['scheme']) ? mb_strtolower($parts['scheme']) : null;

        if ($scheme !== null && ! in_array($scheme, self::PERMITTED_SCHEMES, true)) {
            throw new LinkException('The destination must use http or https.');
        }

        if ($scheme === null || ! isset($parts['host'])) {
            throw new LinkException('The destination must be an absolute URL including a scheme and host.');
        }

        $host = $this->normaliseHost($parts['host']);

        $this->refuseSelfReference($host);
        $this->refuseBlocklisted($host);
        $this->refuseNonPublicAddress($host);

        return $destination;
    }

    /**
     * A destination pointing at one of this instance's own short domains would
     * redirect to itself.
     */
    private function refuseSelfReference(string $host): void
    {
        if ($this->domains->serves($host)) {
            throw new LinkException('The destination cannot point back at this instance.');
        }

        // The interface domain counts too: a link aimed at it would bounce a
        // visitor into the dashboard rather than anywhere useful.
        $appDomain = config('shortynah.domain');

        if (is_string($appDomain) && $appDomain !== '' && mb_strtolower($appDomain) === $host) {
            throw new LinkException('The destination cannot point back at this instance.');
        }
    }

    private function refuseBlocklisted(string $host): void
    {
        foreach ($this->blocklist() as $blocked) {
            // A blocked domain covers its subdomains: blocking example.com and
            // then serving links to ads.example.com would be pointless.
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                throw new LinkException('That destination host is not permitted on this instance.');
            }
        }
    }

    /**
     * A literal address is judged directly; a hostname is judged by every address
     * it resolves to, because one public answer among private ones is still a way
     * in.
     *
     * A host that does not resolve at all is allowed: it may simply be new, and
     * nothing has been shown to be private.
     */
    /**
     * Strips the brackets a URL puts around an IPv6 literal.
     *
     * Without this, `[::ffff:169.254.169.254]` fails the IP check, is treated as a
     * hostname, resolves to nothing, and is allowed — a bypass straight to the
     * cloud metadata service.
     */
    private function normaliseHost(string $host): string
    {
        $host = mb_strtolower($host);

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    private function refuseNonPublicAddress(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! NetworkAddress::isPubliclyRoutable($host)) {
                throw new LinkException('The destination address is not publicly reachable.');
            }

            return;
        }

        foreach ($this->dns->addressesFor($host) as $address) {
            if (! NetworkAddress::isPubliclyRoutable($address)) {
                throw new LinkException('The destination resolves to an address that is not publicly reachable.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function blocklist(): array
    {
        $configured = $this->settings->string('link.destination_blocklist');

        if ($configured === null || trim($configured) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (string $entry): string => mb_strtolower(trim($entry)),
                preg_split('/[\s,]+/', $configured) ?: [],
            ),
            static fn (string $entry): bool => $entry !== '',
        ));
    }
}

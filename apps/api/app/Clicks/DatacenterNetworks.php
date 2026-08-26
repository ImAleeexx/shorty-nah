<?php

declare(strict_types=1);

namespace App\Clicks;

/**
 * Autonomous systems that host machines rather than serve people.
 *
 * A click from a hosting provider is almost never a person, and the user agent
 * often looks like a browser — link previewers and scanners copy real ones. The
 * network is the signal the user agent cannot fake.
 *
 * A short, well-known list rather than an exhaustive one: a false negative merely
 * counts one automated click, while a false positive silently discards real
 * traffic from anyone behind that network.
 */
final class DatacenterNetworks
{
    /**
     * @var array<int, string>
     */
    private const NETWORKS = [
        16509 => 'Amazon AWS',
        14618 => 'Amazon AWS',
        15169 => 'Google',
        396982 => 'Google Cloud',
        8075 => 'Microsoft Azure',
        13335 => 'Cloudflare',
        14061 => 'DigitalOcean',
        63949 => 'Akamai Linode',
        20473 => 'Vultr',
        16276 => 'OVH',
        24940 => 'Hetzner',
        14413 => 'Fastly',
        54113 => 'Fastly',
        36351 => 'IBM SoftLayer',
        45102 => 'Alibaba Cloud',
        132203 => 'Tencent Cloud',
        19551 => 'Incapsula',
        60068 => 'Datacamp',
        9009 => 'M247',
    ];

    public static function organisationFor(int $asn): ?string
    {
        return self::NETWORKS[$asn] ?? null;
    }

    public static function isDatacenter(int $asn): bool
    {
        return $asn !== 0 && isset(self::NETWORKS[$asn]);
    }
}

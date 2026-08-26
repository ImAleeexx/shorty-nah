<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decides whether an address is one the public internet can reach.
 *
 * Two callers need this and both are security-relevant: domain verification must
 * not accept a hostname pointing inside the operator's network, and destination
 * validation must not let a short link aim a visitor's browser at a private host
 * or a cloud metadata service.
 */
final class NetworkAddress
{
    /**
     * Ranges that must never be treated as a public destination. PHP's
     * FILTER_FLAG_NO_PRIV_RANGE and NO_RES_RANGE cover most of these, but not
     * carrier-grade NAT or the cloud metadata address, so the check is explicit.
     *
     * @var list<string>
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // current network
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, includes 169.254.169.254 metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved
        '255.255.255.255/32', // broadcast
    ];

    public static function isPubliclyRoutable(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ! self::inAnyV4Range($address);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::isPublicV6($address);
        }

        return false;
    }

    private static function inAnyV4Range(string $address): bool
    {
        foreach (self::BLOCKED_V4 as $range) {
            if (self::inV4Range($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inV4Range(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $addressLong = ip2long($address);
        $subnetLong = ip2long($subnet);

        if ($addressLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === '0' ? 0 : -1 << (32 - (int) $bits);

        return ($addressLong & $mask) === ($subnetLong & $mask);
    }

    private static function isPublicV6(string $address): bool
    {
        $packed = inet_pton($address);

        if ($packed === false) {
            return false;
        }

        // Unspecified (::) and loopback (::1).
        if ($packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15)."\1") {
            return false;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        // fc00::/7 unique local, fe80::/10 link-local, ff00::/8 multicast.
        if (($first & 0xFE) === 0xFC) {
            return false;
        }

        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return false;
        }

        if ($first === 0xFF) {
            return false;
        }

        // IPv4-mapped (::ffff:0:0/96) is judged by its embedded IPv4 address.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $mapped = inet_ntop(substr($packed, 12));

            return is_string($mapped) && self::isPubliclyRoutable($mapped);
        }

        return true;
    }
}

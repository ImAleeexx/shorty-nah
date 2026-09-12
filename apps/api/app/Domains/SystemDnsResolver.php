<?php

declare(strict_types=1);

namespace App\Domains;

final class SystemDnsResolver implements DnsResolver
{
    /**
     * @return list<string>
     */
    public function addressesFor(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @return list<string>
     */
    public function txtRecordsFor(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            // A record longer than one character-string arrives split in
            // 'entries'; 'txt' is the same record joined back together.
            $value = $record['txt'] ?? null;

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }
}

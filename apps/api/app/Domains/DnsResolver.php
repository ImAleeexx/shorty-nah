<?php

declare(strict_types=1);

namespace App\Domains;

interface DnsResolver
{
    /**
     * Addresses the host currently resolves to.
     *
     * @return list<string>
     */
    public function addressesFor(string $host): array;
}

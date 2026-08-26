<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\DomainRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The gate the edge consults before obtaining a certificate for a hostname.
 *
 * Without it, any hostname pointed at this instance would trigger an issuance
 * attempt and exhaust the certificate authority's rate limits. It answers only
 * for hostnames this instance has verified.
 *
 * Deliberately tiny: this runs before a certificate exists, so it must be fast
 * and must not depend on anything that could be slow or unavailable.
 */
final class TlsAuthorizationController
{
    public function __invoke(Request $request, DomainRegistry $registry): Response
    {
        $host = $request->query('domain');

        if (! is_string($host) || $host === '') {
            return new Response(status: 400);
        }

        // A 2xx approves issuance; anything else declines it.
        return new Response(status: $registry->serves($host) ? 200 : 404);
    }
}

<?php

declare(strict_types=1);

// Required values are passed through untouched so a missing one surfaces as null
// and is reported by shortynah:verify-env, rather than being masked by a default.
return [
    'domain' => env('APP_DOMAIN'),

    /*
     * Peers whose forwarding headers may be believed. Comma-separated addresses
     * or CIDR ranges. A wildcard is rejected by shortynah:verify-env.
     */
    'trusted_proxies' => env('TRUSTED_PROXIES'),
];

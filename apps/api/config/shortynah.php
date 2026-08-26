<?php

declare(strict_types=1);

// Required values are passed through untouched so a missing one surfaces as null
// and is reported by shortynah:verify-env, rather than being masked by a default.
return [
    'domain' => env('APP_DOMAIN'),
];

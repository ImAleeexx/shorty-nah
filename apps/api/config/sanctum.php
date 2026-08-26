<?php

declare(strict_types=1);

use App\Support\ConfigValue;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
     * Same-origin only. The interface and the API share one origin by design, so
     * no third-party host receives a stateful session cookie. A wildcard or a
     * foreign domain here would hand session authentication to another site.
     */
    'stateful' => array_values(array_unique(array_filter([
        ConfigValue::string(env('APP_DOMAIN', 'localhost'), 'APP_DOMAIN'),
        'localhost',
        '127.0.0.1',
        '::1',
    ]))),

    /*
     * Cookie-based requests authenticate through the web guard; bearer tokens
     * are checked by Sanctum itself.
     */
    'guard' => ['web'],

    /*
     * Tokens do not expire by default. An expiry is set per token at creation,
     * so a long-lived integration is a deliberate choice rather than the
     * default for everything.
     */
    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'shortynah_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];

<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateCsrfTokenForCookieRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The trusted-proxy list is applied in AppServiceProvider instead: this
        // closure runs before the config service exists, so it cannot read
        // configuration.

        // The API is first-party and same-origin, so it always runs a session
        // rather than deciding from an Origin header — Sanctum's own stateful
        // middleware infers that from Origin, which a browser sends and a test
        // client does not. Sanctum's guard tries the session first and falls back
        // to a bearer token, so both callers still work.
        //
        // AuthenticateSession is what makes invalidating other sessions take
        // effect: it compares the password hash held in the session on every
        // request. Sanctum's variant is used rather than the framework's, because
        // auth:sanctum makes Sanctum's guard the default and the framework's
        // version calls viaRemember() on it, which a request guard has no.
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ValidateCsrfTokenForCookieRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Validation failures flash old input back into the session; these keys
        // must never make that round trip.
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'link_password',
            'setup_token',
            'recovery_code',
            'one_time_code',
        ]);
    })->create();

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

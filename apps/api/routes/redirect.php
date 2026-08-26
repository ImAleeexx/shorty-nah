<?php

declare(strict_types=1);

use App\Http\Controllers\RedirectController;
use Illuminate\Support\Facades\Route;

/*
 * The redirect path.
 *
 * Registered outside the web middleware group on purpose. A session, a CSRF
 * token and encrypted cookies are all work this route has no use for, and this
 * is the only route a stranger can drive at volume. It carries a per-source rate
 * limit and nothing else.
 *
 * These are the last routes registered, so every named application path — /api,
 * /horizon, /up — is matched before a slug can shadow it.
 */
Route::middleware('throttle:redirect')->group(function (): void {
    Route::get('/{slug}', RedirectController::class)
        ->where('slug', '[A-Za-z0-9_-]{1,64}')
        ->name('redirect');

    Route::post('/{slug}', [RedirectController::class, 'unlock'])
        ->where('slug', '[A-Za-z0-9_-]{1,64}')
        ->name('redirect.unlock');
});

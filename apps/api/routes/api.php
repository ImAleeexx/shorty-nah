<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\ClickBeaconController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\PublicConfigurationController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TlsAuthorizationController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureSetupIsOpen;
use App\Http\Middleware\RequireInstallation;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Http\Middleware\RequireSetupToken;
use Illuminate\Support\Facades\Route;

// The edge asks this before obtaining a certificate for a hostname, which
// happens before any request on that hostname can succeed. It is unauthenticated
// by necessity — the edge has no session — so it discloses nothing beyond
// whether a host is served, and is rate limited against being used as an
// enumeration oracle.
Route::get('/internal/tls-authorize', TlsAuthorizationController::class)
    ->middleware('throttle:tls-authorize')
    ->name('internal.tls-authorize');

// The hold page's measurement. Unauthenticated by necessity — a visitor has no
// session — and admits nothing without a signed, unredeemed click token.
Route::post('/clicks/beacon', ClickBeaconController::class)
    ->middleware('throttle:beacon')
    ->name('clicks.beacon');

Route::prefix('v1')->group(function (): void {
    // Read before anyone signs in, so the interface can render branding on first
    // paint. Carries only the registry's exposed subset.
    Route::get('/config', PublicConfigurationController::class)->name('config.public');

    // First boot only. EnsureSetupIsOpen answers 404 the moment installation
    // completes, so these routes stop existing rather than start refusing.
    Route::prefix('setup')->middleware(EnsureSetupIsOpen::class)->group(function (): void {
        Route::post('/token', [SetupController::class, 'token'])
            ->middleware(['throttle:setup-token', RequireSetupToken::class])
            ->name('setup.token');

        Route::middleware([RequireSetupToken::class])->group(function (): void {
            Route::get('/state', [SetupController::class, 'state'])->name('setup.state');
            Route::post('/connectivity', [SetupController::class, 'connectivity'])->name('setup.connectivity');
            Route::post('/administrator', [SetupController::class, 'administrator'])->name('setup.administrator');
            Route::post('/instance', [SetupController::class, 'instance'])->name('setup.instance');
            Route::post('/branding', [SetupController::class, 'branding'])->name('setup.branding');
            Route::post('/analytics', [SetupController::class, 'analytics'])->name('setup.analytics');
            Route::post('/registration', [SetupController::class, 'registration'])->name('setup.registration');
            Route::post('/mail', [SetupController::class, 'mail'])->name('setup.mail');
            Route::post('/complete', [SetupController::class, 'complete'])->name('setup.complete');
        });
    });

    Route::post('/auth/session', [SessionController::class, 'store'])->name('auth.session.store');
    Route::post('/auth/register', [RegistrationController::class, 'store'])->name('auth.register');

    Route::middleware([RequireInstallation::class, 'auth:sanctum'])->group(function (): void {
        Route::get('/auth/user', [SessionController::class, 'show'])->name('auth.user');
        Route::delete('/auth/session', [SessionController::class, 'destroy'])->name('auth.session.destroy');
        Route::post('/auth/sessions/others', [PasswordController::class, 'destroyOtherSessions'])
            ->name('auth.sessions.others');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');

        Route::get('/links', [LinkController::class, 'index'])->name('links.index');
        Route::get('/links/{link}', [LinkController::class, 'show'])->name('links.show');
        Route::post('/links', [LinkController::class, 'store'])->name('links.store');
        Route::patch('/links/{link}', [LinkController::class, 'update'])->name('links.update');
        Route::delete('/links/{link}', [LinkController::class, 'destroy'])->name('links.destroy');

        Route::get('/links/{link}/report', [AnalyticsController::class, 'report'])->name('links.report');
        Route::get('/links/{link}/events', [AnalyticsController::class, 'events'])->name('links.events');
        Route::get('/links/{link}/export', [AnalyticsController::class, 'export'])->name('links.export');

        Route::get('/branding', [BrandingController::class, 'show'])->name('branding.show');

        Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');

        Route::get('/invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::get('/tokens', [ApiTokenController::class, 'index'])->name('tokens.index');

        // Operations that change credentials, membership or long-lived access
        // require the account to have authenticated recently.
        Route::middleware(RequireRecentAuthentication::class)->group(function (): void {
            Route::put('/auth/password', [PasswordController::class, 'update'])->name('auth.password.update');

            Route::post('/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
            Route::delete('/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');

            Route::post('/invitations', [InvitationController::class, 'store'])->name('invitations.store');
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])
                ->name('invitations.destroy');

            Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');
            Route::post('/branding/assets', [BrandingController::class, 'upload'])->name('branding.upload');

            Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
            Route::post('/domains/{domain}/verify', [DomainController::class, 'verify'])->name('domains.verify');
            Route::post('/domains/{domain}/promote', [DomainController::class, 'promote'])->name('domains.promote');
            Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');

            Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });
    });
});

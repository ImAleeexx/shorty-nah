<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\ClickBeaconController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\LinkRuleController;
use App\Http\Controllers\LinkTransferController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PublicConfigurationController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TlsAuthorizationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\EnsureSetupIsOpen;
use App\Http\Middleware\RequireInstallation;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Http\Middleware\RequireSecondFactor;
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

    // Unauthenticated by necessity: there is no session yet, which is the whole
    // point. The pending account lives in the session, not in the request.
    Route::post('/auth/two-factor/challenge', [TwoFactorController::class, 'challenge'])
        ->name('auth.two-factor.challenge');
    Route::post('/auth/two-factor/challenge/passkey', [TwoFactorController::class, 'passkeyChallengeOptions'])
        ->name('auth.two-factor.challenge.passkey');

    Route::middleware([RequireInstallation::class, 'auth:sanctum'])->group(function (): void {
        Route::get('/auth/user', [SessionController::class, 'show'])->name('auth.user');
        Route::get('/auth/two-factor', [TwoFactorController::class, 'index'])->name('auth.two-factor.index');
        Route::delete('/auth/session', [SessionController::class, 'destroy'])->name('auth.session.destroy');
        Route::post('/auth/sessions/others', [PasswordController::class, 'destroyOtherSessions'])
            ->name('auth.sessions.others');

        // Enrolling a second factor still needs recent authentication, but it
        // must sit above the requirement below: an account confined to
        // enrolment has to be able to reach it.
        Route::middleware(RequireRecentAuthentication::class)->group(function (): void {
            Route::post('/auth/two-factor', [TwoFactorController::class, 'store'])
                ->name('auth.two-factor.store');
            Route::post('/auth/two-factor/{credential}/confirm', [TwoFactorController::class, 'confirm'])
                ->name('auth.two-factor.confirm');
            Route::delete('/auth/two-factor/{credential}', [TwoFactorController::class, 'destroy'])
                ->name('auth.two-factor.destroy');

            Route::post('/auth/two-factor/passkey/options', [TwoFactorController::class, 'passkeyOptions'])
                ->name('auth.two-factor.passkey.options');
            Route::post('/auth/two-factor/passkey', [TwoFactorController::class, 'passkeyStore'])
                ->name('auth.two-factor.passkey.store');
        });

        // Everything past this point needs the second factor the instance
        // requires. Enrolment, sign-out and reading your own account stay
        // reachable above it, or the requirement would be a locked door with the
        // key behind it.
        Route::middleware(RequireSecondFactor::class)->group(function (): void {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');

            Route::get('/links', [LinkController::class, 'index'])->name('links.index');
            // Before /links/{link}, or the router matches `export` and `imports`
            // as link identifiers and every bulk request answers 404.
            Route::get('/overview', OverviewController::class)->name('overview');

            Route::get('/links/export', [LinkTransferController::class, 'export'])->name('links.export.csv');
            Route::post('/links/import', [LinkTransferController::class, 'import'])->name('links.import');
            Route::get('/links/imports/{import}', [LinkTransferController::class, 'show'])->name('links.import.show');
            Route::get('/links/imports/{import}/result', [LinkTransferController::class, 'result'])->name('links.import.result');

            Route::get('/links/{link}', [LinkController::class, 'show'])->name('links.show');
            Route::post('/links', [LinkController::class, 'store'])->name('links.store');
            Route::patch('/links/{link}', [LinkController::class, 'update'])->name('links.update');
            Route::delete('/links/{link}', [LinkController::class, 'destroy'])->name('links.destroy');

            // Read and written as an ordered set: position is the semantics, and
            // reordering one rule at a time would pass through states the unique
            // position constraint refuses.
            // Rendered on request, never stored: a code is derived from the short
            // URL and the accent, and a stored image is a stale image waiting to
            // be served after a rebrand.
            Route::get('/links/{link}/qr', [QrCodeController::class, 'show'])->name('links.qr');

            Route::get('/links/{link}/rules', [LinkRuleController::class, 'index'])->name('links.rules.index');
            Route::put('/links/{link}/rules', [LinkRuleController::class, 'replace'])->name('links.rules.replace');

            Route::get('/links/{link}/report', [AnalyticsController::class, 'report'])->name('links.report');
            Route::get('/links/{link}/events', [AnalyticsController::class, 'events'])->name('links.events');
            Route::get('/links/{link}/export', [AnalyticsController::class, 'export'])->name('links.export');

            Route::get('/branding', [BrandingController::class, 'show'])->name('branding.show');

            // Not behind recent authentication, and the comment below is why: the
            // contract names email, password, second factor, API token and domain
            // deletion, and branding is none of them. It sat in that group anyway,
            // so every save more than fifteen minutes after signing in was refused
            // with a 423 the interface rendered nowhere.
            Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');
            Route::post('/branding/assets', [BrandingController::class, 'upload'])->name('branding.upload');
            Route::delete('/branding/assets/{kind}', [BrandingController::class, 'removeAsset'])->name('branding.asset.remove');

            // Not behind recent authentication: the security contract enumerates the
            // operations that require it — email, password, second factor, API token
            // and domain deletion — and instance configuration is not one of them.
            // Read-only, owner-only, and newest first. There is no write route here
            // and the application's database role holds no privilege to add one.
            Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

            Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

            Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');

            // Registering, proving and promoting a domain are instance
            // configuration. Deleting one is the operation the contract names, and
            // it keeps the challenge below — it destroys the links it served.
            Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
            Route::post('/domains/{domain}/verify', [DomainController::class, 'verify'])->name('domains.verify');
            Route::post('/domains/{domain}/promote', [DomainController::class, 'promote'])->name('domains.promote');

            Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
            Route::get('/webhooks/{endpoint}/deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');
            Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
            Route::patch('/webhooks/{endpoint}', [WebhookController::class, 'update'])->name('webhooks.update');
            Route::post('/webhooks/deliveries/{delivery}/replay', [WebhookController::class, 'replay'])->name('webhooks.replay');

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

                Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');

                // A signing secret is long-lived access to this instance's
                // events, so issuing and destroying one sits with the API tokens
                // rather than with instance configuration.
                Route::post('/webhooks/{endpoint}/rotate', [WebhookController::class, 'rotate'])->name('webhooks.rotate');
                Route::delete('/webhooks/{endpoint}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');

                Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
                Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
            });
        });
    });
});

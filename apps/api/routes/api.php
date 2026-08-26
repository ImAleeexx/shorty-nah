<?php

declare(strict_types=1);

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PublicConfigurationController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\RequireRecentAuthentication;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Read before anyone signs in, so the interface can render branding on first
    // paint. Carries only the registry's exposed subset.
    Route::get('/config', PublicConfigurationController::class)->name('config.public');

    Route::post('/auth/session', [SessionController::class, 'store'])->name('auth.session.store');
    Route::post('/auth/register', [RegistrationController::class, 'store'])->name('auth.register');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('/auth/session', [SessionController::class, 'destroy'])->name('auth.session.destroy');
        Route::post('/auth/sessions/others', [PasswordController::class, 'destroyOtherSessions'])
            ->name('auth.sessions.others');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');

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

            Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });
    });
});

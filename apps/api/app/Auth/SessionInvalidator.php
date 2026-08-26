<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Ends every session for the account except the one making the request.
 *
 * This works by rewriting the password hash the guard stores in the session:
 * Laravel's AuthenticateSession middleware compares it on each request, so
 * sessions carrying the previous hash stop being accepted. That middleware must
 * be in the stack, which is why it is registered explicitly rather than assumed.
 */
final class SessionInvalidator
{
    public static function forgetOtherSessions(string $password): void
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            // The web guard is session-backed by configuration. If that ever
            // changes, failing loudly beats silently leaving sessions alive.
            throw new RuntimeException('The web guard is not session-backed; other sessions cannot be invalidated.');
        }

        $guard->logoutOtherDevices($password);
    }
}

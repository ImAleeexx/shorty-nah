<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive operations require the account to have authenticated recently.
 *
 * A long-lived session idle for hours is a plausible hijack; it should not be
 * enough to change an email address, issue an API token, or remove a second
 * factor.
 */
final class RequireRecentAuthentication
{
    public const WINDOW_SECONDS = 900;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->authenticatedRecently(self::WINDOW_SECONDS)) {
            return new JsonResponse([
                'message' => 'Confirm your password to continue.',
                'requires_reauthentication' => true,
            ], 423);
        }

        return $next($request);
    }
}

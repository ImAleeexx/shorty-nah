<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * CSRF protection for cookie-authenticated requests only.
 *
 * A bearer token is not sent automatically by a browser, so a cross-site request
 * cannot carry one — there is nothing for CSRF to protect. Applying the check to
 * token clients would break every programmatic caller for no gain.
 */
final class ValidateCsrfTokenForCookieRequests extends ValidateCsrfToken
{
    protected function inExceptArray($request): bool
    {
        if ($request->bearerToken() !== null) {
            return true;
        }

        return parent::inExceptArray($request);
    }
}

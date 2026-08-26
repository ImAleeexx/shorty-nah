<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Settings\SettingsStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the setup flow permanently once installation has completed.
 *
 * The answer is `404` rather than `403`: an installed instance should not
 * confirm that a setup surface ever existed, and there is nothing an authorised
 * caller could do here either way.
 */
final class EnsureSetupIsOpen
{
    public function __construct(private readonly SettingsStore $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->settings->installed(), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}

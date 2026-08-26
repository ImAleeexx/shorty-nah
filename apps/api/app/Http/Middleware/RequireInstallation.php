<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Settings\SettingsStore;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds the authenticated API closed until the instance has been installed.
 *
 * Placed ahead of authentication on purpose: before installation there are no
 * accounts, so answering `401` would describe a credential problem the caller
 * cannot possibly fix. `503` says the instance is not ready, which is the truth.
 */
final class RequireInstallation
{
    public function __construct(private readonly SettingsStore $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->settings->installed()) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => 'This instance has not completed setup.',
            'installed' => false,
        ], Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
    }
}

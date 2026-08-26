<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Setup\SetupToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proof of host access, required before the wizard accepts anything.
 *
 * Until an owner exists there is no account to authenticate, so reaching the
 * host's filesystem or its container log is the only credential available — and
 * without it the first stranger to find a freshly deployed instance would own
 * it.
 */
final class RequireSetupToken
{
    public const HEADER = 'X-Setup-Token';

    public function __construct(private readonly SetupToken $token) {}

    public function handle(Request $request, Closure $next): Response
    {
        $presented = $request->header(self::HEADER);

        if (is_string($presented) && $presented !== '' && $this->token->verify($presented)) {
            return $next($request);
        }

        // The address is deliberately absent: raw addresses are not persisted
        // anywhere, diagnostics included. The rate limiter already keys on it.
        Log::warning('Setup token rejected.', ['path' => $request->path()]);

        return new JsonResponse([
            'message' => 'A valid setup token is required.',
        ], Response::HTTP_UNAUTHORIZED, ['Cache-Control' => 'no-store']);
    }
}

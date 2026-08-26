<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\TwoFactor\TwoFactorService;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines an account without a second factor to enrolling one, while the
 * instance-wide requirement is active.
 *
 * The account is authenticated and its session is real, so this is not a `401`.
 * It is `403` with a reason the interface can act on: the caller exists and has
 * somewhere to go.
 */
final class RequireSecondFactor
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->twoFactor->required() || $this->twoFactor->enrolled($user)) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => 'This instance requires a second factor before anything else.',
            'two_factor_enrolment_required' => true,
        ], Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']);
    }
}

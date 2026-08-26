<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\AuthenticationService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class SessionController
{
    /**
     * Attempts are limited per address and per source. Both matter: a per-source
     * limit alone is defeated by a distributed attack on one account, and a
     * per-account limit alone is defeated by spraying many accounts.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 300;

    public function store(Request $request, AuthenticationService $auth): JsonResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        foreach ($this->limiterKeys($request, $credentials['email']) as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                throw ValidationException::withMessages([
                    'email' => sprintf(
                        'Too many attempts. Try again in %d seconds.',
                        RateLimiter::availableIn($key),
                    ),
                ])->status(429);
            }
        }

        $user = $auth->attempt($request, $credentials['email'], $credentials['password']);

        if ($user === null) {
            foreach ($this->limiterKeys($request, $credentials['email']) as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }

            // One message for every failure mode.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        foreach ($this->limiterKeys($request, $credentials['email']) as $key) {
            RateLimiter::clear($key);
        }

        return new JsonResponse([
            'user' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
        ]);
    }

    /**
     * Who the session belongs to. Every authenticated screen needs this to know
     * what the viewer may do, and a role is not something the browser should be
     * asked to remember across a reload.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse([
            'user' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
        ]);
    }

    public function destroy(Request $request, AuthenticationService $auth): JsonResponse
    {
        $auth->signOut($request);

        return new JsonResponse(status: 204);
    }

    /**
     * @return list<string>
     */
    private function limiterKeys(Request $request, string $email): array
    {
        return [
            'auth:account:'.sha1(mb_strtolower($email)),
            'auth:source:'.sha1((string) $request->ip()),
        ];
    }
}

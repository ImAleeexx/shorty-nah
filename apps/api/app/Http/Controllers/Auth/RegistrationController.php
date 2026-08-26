<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\AuthenticationService;
use App\Auth\RegistrationClosedException;
use App\Auth\RegistrationService;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class RegistrationController
{
    public function store(
        Request $request,
        RegistrationService $registration,
        AuthenticationService $auth,
    ): JsonResponse {
        /** @var array{name: string, email: string, password: string, invitation_token?: string} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', new StrongPassword],
            'invitation_token' => ['nullable', 'string'],
        ]);

        try {
            $user = $registration->register(
                name: $input['name'],
                email: $input['email'],
                password: $input['password'],
                invitationToken: $input['invitation_token'] ?? null,
            );
        } catch (RegistrationClosedException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()])->status(403);
        }

        $auth->establishSession($request, $user);

        return new JsonResponse([
            'user' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
        ], 201);
    }
}

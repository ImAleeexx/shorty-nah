<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\InvitationService;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class InvitationController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->administrates()) {
            return new JsonResponse(status: 404);
        }

        $invitations = Invitation::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (Invitation $invitation): array => [
                'id' => $invitation->public_id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at,
                'accepted_at' => $invitation->accepted_at,
                'revoked_at' => $invitation->revoked_at,
            ]);

        return new JsonResponse(['invitations' => $invitations]);
    }

    public function store(Request $request, InvitationService $invitations): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->administrates()) {
            return new JsonResponse(status: 404);
        }

        /** @var array{email: string, role: string, lifetime_days?: int|null} $input */
        $input = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'in:'.implode(',', array_column(Role::cases(), 'value'))],
            'lifetime_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        try {
            $issued = $invitations->issue(
                inviter: $user,
                email: $input['email'],
                role: Role::from($input['role']),
                lifetimeDays: $input['lifetime_days'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['role' => $e->getMessage()])->status(403);
        }

        return new JsonResponse([
            'id' => $issued['invitation']->public_id,
            'email' => $issued['invitation']->email,
            'role' => $issued['invitation']->role->value,
            'expires_at' => $issued['invitation']->expires_at,
            // Shown once.
            'token' => $issued['token'],
        ], 201);
    }

    public function destroy(Request $request, Invitation $invitation, InvitationService $invitations): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->administrates()) {
            return new JsonResponse(status: 404);
        }

        $invitations->revoke($invitation);

        return new JsonResponse(status: 204);
    }
}

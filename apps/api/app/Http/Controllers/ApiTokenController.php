<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Named, scoped API tokens.
 *
 * The value is returned once, at creation. Only a hash is stored, so it cannot
 * be recovered afterwards — losing it means issuing a new one.
 */
final class ApiTokenController
{
    /**
     * Abilities a token may be granted. A token cannot exceed its owner's role
     * at use time, so this list bounds what it may attempt, not what it may do.
     */
    public const ABILITIES = [
        'links:read',
        'links:write',
        'analytics:read',
        'domains:read',
        'domains:write',
    ];

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tokens = $user->tokens()->orderByDesc('created_at')->get()->map(
            static fn (PersonalAccessToken $token): array => [
                'id' => $token->getKey(),
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
            ]
        );

        return new JsonResponse(['tokens' => $tokens]);
    }

    public function store(Request $request, AuditLog $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{name: string, abilities: list<string>, expires_in_days?: int|null} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', self::ABILITIES)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $lifetime = $input['expires_in_days'] ?? null;
        $expiresAt = $lifetime === null ? null : Carbon::now()->addDays($lifetime);

        $token = $user->createToken($input['name'], $input['abilities'], $expiresAt);

        // The plaintext token is shown to the caller once and recorded nowhere,
        // audit entry included.
        $audit->record(
            AuditAction::TokenCreated,
            actor: $user,
            targetType: 'token',
            targetId: (string) $token->accessToken->getKey(),
            context: ['name' => $input['name'], 'abilities' => implode(',', $input['abilities'])],
            request: $request,
        );

        return new JsonResponse([
            'id' => $token->accessToken->getKey(),
            'name' => $input['name'],
            'abilities' => $input['abilities'],
            'expires_at' => $expiresAt,
            // Shown once. Never recoverable.
            'token' => $token->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request, string $token, AuditLog $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleted = $user->tokens()->where('id', $token)->delete();

        if ($deleted > 0) {
            $audit->record(
                AuditAction::TokenRevoked,
                actor: $user,
                targetType: 'token',
                targetId: $token,
                request: $request,
            );
        }

        // A token belonging to someone else is indistinguishable from one that
        // never existed.
        return new JsonResponse(status: $deleted > 0 ? 204 : 404);
    }
}

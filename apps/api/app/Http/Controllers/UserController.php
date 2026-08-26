<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class UserController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $actor->administrates()) {
            return new JsonResponse(status: 404);
        }

        return new JsonResponse([
            'users' => User::query()->orderBy('name')->get()->map($this->present(...)),
        ]);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $target = User::query()->where('public_id', $publicId)->first();

        // A record the actor may not read answers exactly as one that does not
        // exist. A 403 would confirm the account is real.
        if (! $target instanceof User || ! $this->mayRead($actor, $target)) {
            return new JsonResponse(status: 404);
        }

        return new JsonResponse(['user' => $this->present($target)]);
    }

    public function updateRole(Request $request, string $publicId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $target = User::query()->where('public_id', $publicId)->first();

        if (! $target instanceof User || ! $actor->administrates()) {
            return new JsonResponse(status: 404);
        }

        /** @var array{role: string} $input */
        $input = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', array_column(Role::cases(), 'value'))],
        ]);

        $role = Role::from($input['role']);

        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'role' => 'You cannot change your own role.',
            ])->status(403);
        }

        if (! $actor->role->mayGrant($role)) {
            throw ValidationException::withMessages([
                'role' => 'You cannot grant a role above your own.',
            ])->status(403);
        }

        if (! $actor->role->mayGrant($target->role)) {
            throw ValidationException::withMessages([
                'role' => 'You cannot change the role of an account above your own.',
            ])->status(403);
        }

        if ($target->isOwner() && $role !== Role::Owner && User::ownerCount() <= 1) {
            throw ValidationException::withMessages([
                'role' => 'The instance must keep at least one owner.',
            ])->status(422);
        }

        $target->forceFill(['role' => $role->value])->save();

        return new JsonResponse(['user' => $this->present($target->refresh())]);
    }

    public function destroy(Request $request, string $publicId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $target = User::query()->where('public_id', $publicId)->first();

        if (! $target instanceof User || ! $actor->administrates()) {
            return new JsonResponse(status: 404);
        }

        if (! $actor->role->mayGrant($target->role)) {
            return new JsonResponse(status: 404);
        }

        if ($target->isOwner() && User::ownerCount() <= 1) {
            throw ValidationException::withMessages([
                'user' => 'The instance must keep at least one owner.',
            ])->status(422);
        }

        $target->delete();

        return new JsonResponse(status: 204);
    }

    private function mayRead(User $actor, User $target): bool
    {
        return $actor->administrates() || $actor->is($target);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'disabled' => $user->isDisabled(),
        ];
    }
}

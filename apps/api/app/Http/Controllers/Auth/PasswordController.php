<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\SessionInvalidator;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class PasswordController
{
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{current_password: string, password: string} $input */
        $input = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', new StrongPassword],
        ]);

        if (! Hash::check($input['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That password is incorrect.',
            ]);
        }

        $user->forceFill(['password' => $input['password']])->save();

        // Rotates the remember token and records the change instant, which is
        // what stops sessions issued before now from being accepted.
        $user->markPasswordChanged();

        // The acting session stays usable; every other one does not.
        SessionInvalidator::forgetOtherSessions($input['password']);

        return new JsonResponse(status: 204);
    }

    /**
     * Ends every other session for the account without changing the password.
     */
    public function destroyOtherSessions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{password: string} $input */
        $input = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($input['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password is incorrect.']);
        }

        SessionInvalidator::forgetOtherSessions($input['password']);

        $user->forceFill(['remember_token' => null])->save();

        return new JsonResponse(status: 204);
    }
}

<?php

declare(strict_types=1);

namespace App\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The gap between a correct password and a session.
 *
 * The account is held in the session rather than handed to the client as a
 * token, so nothing the browser can edit decides who is about to be signed in.
 * It expires on its own: an abandoned challenge must not stay redeemable.
 */
final class PendingChallenge
{
    private const KEY = 'two_factor.pending';

    private const LIFETIME_SECONDS = 300;

    public function begin(Request $request, User $user): void
    {
        $request->session()->put(self::KEY, [
            'user_id' => $user->id,
            'expires_at' => Carbon::now()->addSeconds(self::LIFETIME_SECONDS)->getTimestamp(),
        ]);
    }

    public function user(Request $request): ?User
    {
        /** @var array{user_id?: int, expires_at?: int}|null $pending */
        $pending = $request->session()->get(self::KEY);

        if (! is_array($pending) || ! isset($pending['user_id'], $pending['expires_at'])) {
            return null;
        }

        if (Carbon::now()->getTimestamp() > $pending['expires_at']) {
            $this->clear($request);

            return null;
        }

        $user = User::query()->find($pending['user_id']);

        return $user instanceof User ? $user : null;
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}

<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Password authentication.
 *
 * Every failure — unknown address, wrong password, disabled account — produces
 * the same outcome and does the same amount of work, so a caller cannot learn
 * which accounts exist by watching responses or timing them.
 */
final class AuthenticationService
{
    /**
     * A valid hash of a value nobody will submit. Verifying against it costs the
     * same as verifying a real password, which is what removes the timing
     * difference for an unknown address.
     */
    private ?string $decoyHash = null;

    public function __construct(private readonly Hasher $hasher) {}

    public function attempt(Request $request, string $email, string $password): ?User
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        if (! $user instanceof User) {
            $this->burnEquivalentWork($password);

            return null;
        }

        if (! $this->hasher->check($password, $user->password)) {
            return null;
        }

        if ($user->isDisabled()) {
            // Checked after the password so a disabled account is not
            // distinguishable from a wrong password by timing.
            return null;
        }

        $this->rehashIfNeeded($user, $password);
        $this->establishSession($request, $user);

        return $user;
    }

    /**
     * Signing in issues a new session identifier, so a session fixed before
     * authentication cannot be reused afterwards.
     */
    public function establishSession(Request $request, User $user): void
    {
        Auth::guard('web')->login($user, remember: false);

        $request->session()->regenerate();

        $user->forceFill(['last_authenticated_at' => Carbon::now()])->save();
    }

    public function signOut(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function rehashIfNeeded(User $user, string $password): void
    {
        if (! $this->hasher->needsRehash($user->password)) {
            return;
        }

        // Raising the work factor takes effect for an account the next time it
        // signs in, without anyone resetting a password.
        $user->forceFill(['password' => $password])->save();
    }

    private function burnEquivalentWork(string $password): void
    {
        $this->decoyHash ??= $this->hasher->make('decoy-for-timing-equalisation');

        $this->hasher->check($password, $this->decoyHash);
    }
}

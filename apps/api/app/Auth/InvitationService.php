<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues and redeems invitations.
 *
 * The token is generated here, returned once, and never stored: only its hash
 * reaches the database, so a leaked backup cannot be turned into a working
 * invitation.
 */
final class InvitationService
{
    public const DEFAULT_LIFETIME_DAYS = 7;

    /**
     * @return array{invitation: Invitation, token: string}
     */
    public function issue(User $inviter, string $email, Role $role, ?int $lifetimeDays = null): array
    {
        if (! $inviter->administrates()) {
            throw new RuntimeException('Only an administrator may issue an invitation.');
        }

        if (! $inviter->role->mayGrant($role)) {
            throw new RuntimeException('An invitation may not grant a role above the inviter\'s own.');
        }

        $token = Str::random(48);

        $invitation = new Invitation;
        $invitation->forceFill([
            'email' => mb_strtolower($email),
            'role' => $role->value,
            'token_hash' => $this->hash($token),
            'invited_by' => $inviter->id,
            'expires_at' => Carbon::now()->addDays($lifetimeDays ?? self::DEFAULT_LIFETIME_DAYS),
        ])->save();

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * The redeemable invitation for a token, or null. Expiry, revocation and
     * prior use are all indistinguishable to the caller on purpose.
     */
    public function find(string $token): ?Invitation
    {
        $invitation = Invitation::query()->where('token_hash', $this->hash($token))->first();

        if (! $invitation instanceof Invitation) {
            return null;
        }

        return $invitation->isRedeemable() ? $invitation : null;
    }

    public function markAccepted(Invitation $invitation): void
    {
        $invitation->forceFill(['accepted_at' => Carbon::now()])->save();
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => Carbon::now()])->save();
    }

    /**
     * SHA-256 rather than a slow hash: the token is 48 random characters, so
     * there is no low-entropy secret for a slow hash to protect. Constant-time
     * lookup comes from matching the hash, never the token.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

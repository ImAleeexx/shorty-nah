<?php

declare(strict_types=1);

namespace App\Auth\TwoFactor;

use App\Models\RecoveryCode;
use App\Models\TwoFactorCredential;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OTPHP\TOTP;

/**
 * Enrolment, verification and recovery for second factors.
 *
 * The rule that shapes everything here: a factor is not active until it has been
 * proved once. An unconfirmed enrolment is inert, so a mistyped secret cannot
 * lock somebody out of their own instance.
 */
final class TwoFactorService
{
    public const RECOVERY_CODE_COUNT = 10;

    /** How many time steps either side of now are accepted, for clock drift. */
    private const LEEWAY_STEPS = 1;

    private const PERIOD = 30;

    public function __construct(private readonly SettingsStore $settings) {}

    public function required(): bool
    {
        return $this->settings->boolean('security.two_factor_required');
    }

    public function enrolled(User $user): bool
    {
        return $this->confirmedCredentials($user)->isNotEmpty();
    }

    /**
     * @return Collection<int, TwoFactorCredential>
     */
    public function confirmedCredentials(User $user): Collection
    {
        return TwoFactorCredential::query()
            ->where('user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Begin an authenticator enrolment. The secret is stored immediately but the
     * factor stays inert until a generated code proves it arrived intact.
     *
     * @return array{credential: TwoFactorCredential, secret: string, uri: string}
     */
    public function beginTotpEnrolment(User $user, string $name, string $issuer): array
    {
        $totp = TOTP::generate();
        $totp->setPeriod(self::PERIOD);
        $label = $user->email !== '' ? $user->email : 'account';
        $totp->setLabel($label);
        $totp->setIssuer($issuer !== '' ? $issuer : 'Shorty-Nah');

        $credential = new TwoFactorCredential;
        $credential->forceFill([
            'user_id' => $user->id,
            'type' => TwoFactorCredential::TOTP,
            'name' => $name,
            'secret' => $totp->getSecret(),
            'confirmed_at' => null,
        ])->save();

        return [
            'credential' => $credential->refresh(),
            'secret' => $totp->getSecret(),
            'uri' => $totp->getProvisioningUri(),
        ];
    }

    /**
     * Confirm an enrolment. Returns the recovery codes if this is the account's
     * first factor, and null otherwise — they are issued once, not per factor.
     *
     * @return list<string>|null
     */
    public function confirmTotp(TwoFactorCredential $credential, string $code): ?array
    {
        if ($credential->type !== TwoFactorCredential::TOTP || $credential->isConfirmed()) {
            throw new TwoFactorException('That enrolment cannot be confirmed.');
        }

        if (! $this->acceptTotp($credential, $code)) {
            throw new TwoFactorException('That code is not correct.');
        }

        $user = $this->ownerOf($credential);
        $first = ! $this->enrolled($user);

        $credential->forceFill(['confirmed_at' => Carbon::now()])->save();

        return $first ? $this->issueRecoveryCodes($user) : null;
    }

    /**
     * Verify a code against any confirmed authenticator the account holds.
     */
    public function verifyTotp(User $user, string $code): bool
    {
        foreach ($this->confirmedCredentials($user) as $credential) {
            if ($credential->type !== TwoFactorCredential::TOTP) {
                continue;
            }

            if ($this->acceptTotp($credential, $code)) {
                $credential->forceFill(['last_used_at' => Carbon::now()])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function issueRecoveryCodes(User $user): array
    {
        RecoveryCode::query()->where('user_id', $user->id)->delete();

        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = Str::lower(Str::random(5).'-'.Str::random(5));
            $codes[] = $code;

            RecoveryCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => $this->hash($code),
            ]);
        }

        return $codes;
    }

    /**
     * Spend a recovery code. Returns how many remain, or null if it was refused.
     */
    public function consumeRecoveryCode(User $user, string $code): ?int
    {
        $match = RecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('code_hash', $this->hash(Str::lower(trim($code))))
            ->whereNull('used_at')
            ->first();

        if (! $match instanceof RecoveryCode) {
            return null;
        }

        $match->forceFill(['used_at' => Carbon::now()])->save();

        return $this->remainingRecoveryCodes($user);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return RecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->count();
    }

    /**
     * Remove a factor. Refused when it is the last one and the instance requires
     * a second factor, because the alternative is an account that cannot sign in
     * and cannot enrol either.
     */
    public function remove(TwoFactorCredential $credential): void
    {
        $user = $this->ownerOf($credential);

        if ($this->required() && $this->confirmedCredentials($user)->count() <= 1 && $credential->isConfirmed()) {
            throw new TwoFactorException(
                'This instance requires a second factor, so the last one cannot be removed.'
            );
        }

        $credential->delete();

        if (! $this->enrolled($user)) {
            // Recovery codes exist to get past a second factor; with none left
            // they are just a standing credential nobody needs.
            RecoveryCode::query()->where('user_id', $user->id)->delete();
        }
    }

    /**
     * The account a credential belongs to.
     *
     * The relation is nullable in the schema only because the column is; a
     * credential without an owner is a corrupt row, not a state to handle.
     */
    private function ownerOf(TwoFactorCredential $credential): User
    {
        $user = $credential->user;

        if (! $user instanceof User) {
            throw new TwoFactorException('That credential has no account.');
        }

        return $user;
    }

    /**
     * Accept a code once and only once.
     *
     * The time step is recorded rather than the code, so a replay is refused by
     * arithmetic rather than by remembering every code ever seen. Anything at or
     * before the last accepted step is a replay whatever its digits say.
     */
    private function acceptTotp(TwoFactorCredential $credential, string $code): bool
    {
        $secret = $credential->secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $code = preg_replace('/\s+/', '', $code) ?? $code;
        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(self::PERIOD);

        $now = Carbon::now()->getTimestamp();

        for ($offset = -self::LEEWAY_STEPS; $offset <= self::LEEWAY_STEPS; $offset++) {
            $at = max(0, $now + ($offset * self::PERIOD));
            $step = intdiv($at, self::PERIOD);

            if ($credential->last_timestep !== null && $step <= $credential->last_timestep) {
                continue;
            }

            if (! hash_equals($totp->at($at), $code)) {
                continue;
            }

            $credential->forceFill(['last_timestep' => $step])->save();

            return true;
        }

        return false;
    }

    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}

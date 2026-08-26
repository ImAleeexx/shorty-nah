<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Support\Carbon;

/**
 * Creates accounts under the instance's active registration mode.
 *
 * The mode is read at the moment of the attempt, so switching it takes effect
 * immediately and never affects accounts that already exist.
 */
final class RegistrationService
{
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly InvitationService $invitations,
    ) {}

    public function mode(): RegistrationMode
    {
        return RegistrationMode::tryFrom((string) $this->settings->get('registration.mode'))
            ?? RegistrationMode::Closed;
    }

    /**
     * Register a new account. Returns the account, or throws if the active mode
     * forbids it.
     */
    public function register(string $name, string $email, string $password, ?string $invitationToken = null): User
    {
        $mode = $this->mode();

        return match ($mode) {
            RegistrationMode::Closed => throw new RegistrationClosedException('Registration is closed on this instance.'),
            RegistrationMode::Invite => $this->registerWithInvitation($name, $email, $password, $invitationToken),
            RegistrationMode::Open => $this->create($name, $email, $password, Role::Member),
        };
    }

    private function registerWithInvitation(string $name, string $email, string $password, ?string $token): User
    {
        if ($token === null || $token === '') {
            throw new RegistrationClosedException('An invitation is required to register on this instance.');
        }

        $invitation = $this->invitations->find($token);

        if ($invitation === null) {
            // Expired, revoked and already-used are deliberately one outcome: the
            // caller learns only that the token does not work.
            throw new RegistrationClosedException('That invitation is not valid.');
        }

        $user = $this->create($name, $email, $password, $invitation->role);

        $this->invitations->markAccepted($invitation);

        return $user;
    }

    private function create(string $name, string $email, string $password, Role $role): User
    {
        $user = new User;

        // Role is set here rather than filled, because it is never accepted from
        // request input.
        $user->forceFill([
            'name' => $name,
            'email' => mb_strtolower($email),
            'password' => $password,
            'role' => $role->value,
            'password_changed_at' => Carbon::now(),
        ])->save();

        return $user->refresh();
    }

    /**
     * The first account on a fresh instance becomes the owner, regardless of
     * mode. Guarded by the setup token, not by the registration mode.
     */
    public function createOwner(string $name, string $email, string $password): User
    {
        return $this->create($name, $email, $password, Role::Owner);
    }

    /**
     * The instance owner, if one has been created. Ordered by id so an instance
     * that later gains a second owner still resolves to the original.
     */
    public function owner(): ?User
    {
        $owner = User::query()
            ->where('role', Role::Owner->value)
            ->orderBy('id')
            ->first();

        return $owner instanceof User ? $owner : null;
    }
}

<?php

declare(strict_types=1);

use App\Auth\InvitationService;
use App\Enums\Role;
use App\Models\User;
use App\Settings\SettingsStore;

function mode(string $mode): void
{
    app(SettingsStore::class)->set('registration.mode', $mode);
}

function payload(array $overrides = []): array
{
    return array_merge([
        'name' => 'New Operator',
        'email' => 'new@example.test',
        'password' => 'a-long-enough-passphrase',
    ], $overrides);
}

it('refuses registration while closed', function (): void {
    mode('closed');

    $this->postJson('/api/v1/auth/register', payload())->assertStatus(403);

    expect(User::query()->count())->toBe(0);
});

it('registers with a valid invitation', function (): void {
    mode('invite');

    $admin = User::factory()->admin()->create();
    $issued = app(InvitationService::class)->issue($admin, 'new@example.test', Role::Member);

    $this->postJson('/api/v1/auth/register', payload(['invitation_token' => $issued['token']]))
        ->assertCreated()
        ->assertJsonPath('user.role', 'member');

    expect($issued['invitation']->refresh()->accepted_at)->not->toBeNull();
});

it('takes the role from the invitation, not the request', function (): void {
    mode('invite');

    $owner = User::factory()->owner()->create();
    $issued = app(InvitationService::class)->issue($owner, 'new@example.test', Role::Viewer);

    $this->postJson('/api/v1/auth/register', payload([
        'invitation_token' => $issued['token'],
        'role' => 'owner',
    ]))->assertCreated()->assertJsonPath('user.role', 'viewer');
});

it('refuses registration without an invitation while invite-only', function (): void {
    mode('invite');

    $this->postJson('/api/v1/auth/register', payload())->assertStatus(403);

    expect(User::query()->count())->toBe(0);
});

it('refuses a spent invitation', function (string $state): void {
    mode('invite');

    $admin = User::factory()->admin()->create();
    $invitations = app(InvitationService::class);
    $issued = $invitations->issue($admin, 'new@example.test', Role::Member);

    match ($state) {
        'accepted' => $invitations->markAccepted($issued['invitation']),
        'revoked' => $invitations->revoke($issued['invitation']),
        'expired' => $issued['invitation']->forceFill(['expires_at' => now()->subDay()])->save(),
    };

    $this->postJson('/api/v1/auth/register', payload(['invitation_token' => $issued['token']]))
        ->assertStatus(403);

    expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
})->with(['accepted', 'revoked', 'expired']);

it('refuses an invented invitation token', function (): void {
    mode('invite');

    $this->postJson('/api/v1/auth/register', payload(['invitation_token' => str_repeat('a', 48)]))
        ->assertStatus(403);
});

it('registers anyone while open, as a member', function (): void {
    mode('open');

    $this->postJson('/api/v1/auth/register', payload())
        ->assertCreated()
        ->assertJsonPath('user.role', 'member');
});

it('leaves existing accounts alone when the mode closes', function (): void {
    mode('open');
    $this->postJson('/api/v1/auth/register', payload())->assertCreated();

    mode('closed');

    $user = User::query()->where('email', 'new@example.test')->firstOrFail();

    $this->postJson('/api/v1/auth/session', [
        'email' => 'new@example.test',
        'password' => 'a-long-enough-passphrase',
    ])->assertOk()->assertJsonPath('user.id', $user->public_id);
});

it('rejects a password below the minimum length', function (string $password): void {
    mode('open');

    $this->postJson('/api/v1/auth/register', payload(['password' => $password]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
})->with(['short', 'Password123', 'letmein']);

it('rejects a long but commonly used password', function (string $password): void {
    // The length check already catches most leaked passwords, so the bundled
    // list exists for the long-but-predictable ones: keyboard walks, repeated
    // words, and anything an operator might reach for on this instance.
    mode('open');

    $this->postJson('/api/v1/auth/register', payload(['password' => $password]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
})->with([
    'password1234',
    'administrator',
    'qwertyuiopasdfgh',
    'linkshortener',
    'changemeplease',
]);

it('ignores case when matching the common list', function (): void {
    mode('open');

    $this->postJson('/api/v1/auth/register', payload(['password' => 'AdMiNiStRaToR']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('accepts a long passphrase that is not on the common list', function (): void {
    mode('open');

    $this->postJson('/api/v1/auth/register', payload(['password' => 'quiet lantern harbour drift']))
        ->assertCreated();
});

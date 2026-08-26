<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('auth:account:'.sha1('operator@example.test'));
    RateLimiter::clear('auth:source:'.sha1('127.0.0.1'));
});

it('signs in with correct credentials', function (): void {
    $user = User::factory()->create(['email' => 'operator@example.test']);

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk()->assertJson(['user' => ['id' => $user->public_id, 'role' => 'member']]);

    expect(auth()->check())->toBeTrue();
});

it('records the authentication instant', function (): void {
    $user = User::factory()->create(['email' => 'operator@example.test', 'last_authenticated_at' => null]);

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    expect($user->refresh()->last_authenticated_at)->not->toBeNull();
});

it('issues a new session identifier on sign in', function (): void {
    User::factory()->create(['email' => 'operator@example.test']);

    $this->get('/api/v1/config');
    $before = session()->getId();

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    // A session fixed before authentication must not survive it.
    expect(session()->getId())->not->toBe($before);
});

it('answers the same way for a wrong password and an unknown address', function (): void {
    User::factory()->create(['email' => 'operator@example.test']);

    $wrongPassword = $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => 'not-the-password',
    ]);

    RateLimiter::clear('auth:account:'.sha1('operator@example.test'));
    RateLimiter::clear('auth:source:'.sha1('127.0.0.1'));

    $unknownAddress = $this->postJson('/api/v1/auth/session', [
        'email' => 'nobody@example.test',
        'password' => 'not-the-password',
    ]);

    expect($wrongPassword->status())->toBe($unknownAddress->status())
        ->and($wrongPassword->json('errors.email'))->toBe($unknownAddress->json('errors.email'));
});

it('refuses a disabled account without saying so', function (): void {
    User::factory()->disabled()->create(['email' => 'operator@example.test']);

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Those credentials do not match our records.');
});

it('rate limits repeated failures for one account', function (): void {
    User::factory()->create(['email' => 'operator@example.test']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/session', [
            'email' => 'operator@example.test',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertStatus(429);
});

it('clears the limiter after a successful sign in', function (): void {
    User::factory()->create(['email' => 'operator@example.test']);

    $this->postJson('/api/v1/auth/session', ['email' => 'operator@example.test', 'password' => 'wrong'])
        ->assertStatus(422);

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    expect(RateLimiter::attempts('auth:account:'.sha1('operator@example.test')))->toBe(0);
});

it('rehashes a password when the work factor rises', function (): void {
    // A hash produced at a lower cost than the current configuration.
    $weakHash = password_hash(UserFactory::PASSWORD, PASSWORD_ARGON2ID, [
        'memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1,
    ]);

    $user = User::factory()->create(['email' => 'operator@example.test']);
    $user->forceFill(['password' => $weakHash])->save();

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    $stored = $user->refresh()->password;

    expect($stored)->not->toBe($weakHash)
        ->and(Hash::needsRehash($stored))->toBeFalse()
        ->and(Hash::check(UserFactory::PASSWORD, $stored))->toBeTrue();
});

it('signs out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/api/v1/auth/session')->assertNoContent();
});

it('stores only an argon2id hash, never the password', function (): void {
    $user = User::factory()->create();

    expect($user->password)->toStartWith('$argon2id$')
        ->and($user->password)->not->toContain(UserFactory::PASSWORD);
});

it('reports who the session belongs to', function (): void {
    $user = User::factory()->create(['role' => Role::Member]);

    $this->actingAs($user)
        ->getJson('/api/v1/auth/user')
        ->assertOk()
        ->assertJsonPath('user.id', $user->public_id)
        ->assertJsonPath('user.role', 'member');
});

it('refuses to say who the session belongs to without one', function (): void {
    $this->getJson('/api/v1/auth/user')->assertStatus(401);
});

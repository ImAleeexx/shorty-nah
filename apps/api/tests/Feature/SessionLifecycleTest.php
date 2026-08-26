<?php

declare(strict_types=1);

use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;

it('marks the session cookie secure, http-only and same-site', function (): void {
    config()->set('session.secure', true);

    User::factory()->create(['email' => 'operator@example.test']);

    $response = $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    $cookie = collect($response->headers->getCookies())
        ->firstOrFail(fn ($c): bool => $c->getName() === config('session.cookie'));

    expect($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('records the password change instant and rotates the remember token', function (): void {
    $user = User::factory()->freshlyAuthenticated()->create();
    $tokenBefore = $user->remember_token;

    $this->actingAs($user)->putJson('/api/v1/auth/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'a-quiet-lantern-drifts',
    ])->assertNoContent();

    $user->refresh();

    expect($user->password_changed_at)->not->toBeNull()
        ->and($user->remember_token)->not->toBe($tokenBefore)
        ->and(Hash::check('a-quiet-lantern-drifts', $user->password))->toBeTrue();
});

it('refuses a password change without the current password', function (): void {
    $user = User::factory()->freshlyAuthenticated()->create();

    $this->actingAs($user)->putJson('/api/v1/auth/password', [
        'current_password' => 'not-the-password',
        'password' => 'a-quiet-lantern-drifts',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');

    expect(Hash::check(UserFactory::PASSWORD, $user->refresh()->password))->toBeTrue();
});

it('applies the password policy to a change', function (): void {
    $user = User::factory()->freshlyAuthenticated()->create();

    $this->actingAs($user)->putJson('/api/v1/auth/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'administrator',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('ends other sessions on request', function (): void {
    $user = User::factory()->freshlyAuthenticated()->create();

    $this->actingAs($user)->postJson('/api/v1/auth/sessions/others', [
        'password' => UserFactory::PASSWORD,
    ])->assertNoContent();

    expect($user->refresh()->remember_token)->toBeNull();
});

it('challenges a sensitive operation when authentication is stale', function (): void {
    $user = User::factory()->staleAuthentication()->create();

    $this->actingAs($user)->postJson('/api/v1/tokens', [
        'name' => 'CI',
        'abilities' => ['links:read'],
    ])->assertStatus(423)->assertJson(['requires_reauthentication' => true]);

    expect($user->tokens()->count())->toBe(0);
});

it('allows a sensitive operation shortly after authenticating', function (): void {
    $user = User::factory()->freshlyAuthenticated()->create();

    $this->actingAs($user)->postJson('/api/v1/tokens', [
        'name' => 'CI',
        'abilities' => ['links:read'],
    ])->assertCreated();
});

it('challenges once the re-authentication window elapses', function (): void {
    $user = User::factory()->create([
        'last_authenticated_at' => now()->subSeconds(RequireRecentAuthentication::WINDOW_SECONDS + 5),
    ]);

    $this->actingAs($user)->postJson('/api/v1/tokens', [
        'name' => 'CI',
        'abilities' => ['links:read'],
    ])->assertStatus(423);
});

it('leaves non-sensitive reads available with a stale session', function (): void {
    $user = User::factory()->staleAuthentication()->create();

    $this->actingAs($user)->getJson('/api/v1/tokens')->assertOk();
});

it('refreshes the window by signing in again', function (): void {
    $user = User::factory()->staleAuthentication()->create(['email' => 'operator@example.test']);

    // Driven entirely over HTTP rather than through actingAs, which would pin a
    // stale model instance for the rest of the test and never observe the
    // refreshed instant.
    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    expect($user->refresh()->authenticatedRecently(RequireRecentAuthentication::WINDOW_SECONDS))->toBeTrue();

    $this->postJson('/api/v1/tokens', [
        'name' => 'CI', 'abilities' => ['links:read'],
    ])->assertCreated();
});

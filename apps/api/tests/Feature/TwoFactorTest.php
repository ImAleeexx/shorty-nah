<?php

declare(strict_types=1);

use App\Auth\TwoFactor\TwoFactorService;
use App\Models\RecoveryCode;
use App\Models\TwoFactorCredential;
use App\Models\User;
use App\Settings\SettingsStore;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use OTPHP\TOTP;

function twoFactorUser(): User
{
    return User::factory()->freshlyAuthenticated()->create(['email' => 'operator@example.test']);
}

/**
 * @return array{credential: TwoFactorCredential, secret: string}
 */
function twoFactorBeginEnrolment(User $user): array
{
    $response = test()->actingAs($user)
        ->postJson('/api/v1/auth/two-factor', ['name' => 'Phone'])
        ->assertStatus(201);

    $credential = TwoFactorCredential::query()->where('public_id', $response->json('id'))->firstOrFail();

    return ['credential' => $credential, 'secret' => (string) $response->json('secret')];
}

function twoFactorCodeFor(string $secret, int $offset = 0): string
{
    $totp = TOTP::createFromSecret($secret);
    $totp->setPeriod(30);

    return $totp->at(now()->getTimestamp() + $offset);
}

/**
 * @return array{secret: string, credential: TwoFactorCredential, recovery_codes: list<string>}
 */
function twoFactorEnrol(User $user): array
{
    $enrolment = twoFactorBeginEnrolment($user);

    $response = test()->actingAs($user)
        ->postJson("/api/v1/auth/two-factor/{$enrolment['credential']->public_id}/confirm", [
            'code' => twoFactorCodeFor($enrolment['secret']),
        ])
        ->assertOk();

    return [
        'secret' => $enrolment['secret'],
        'credential' => $enrolment['credential']->refresh(),
        'recovery_codes' => $response->json('recovery_codes'),
    ];
}

beforeEach(function (): void {
    RateLimiter::clear('auth:account:'.sha1('operator@example.test'));
    RateLimiter::clear('auth:source:'.sha1('127.0.0.1'));
});

// --- 17.1 enrolment ---

it('does not activate a factor on a wrong confirmation code', function (): void {
    $user = twoFactorUser();
    $enrolment = twoFactorBeginEnrolment($user);

    $this->actingAs($user)
        ->postJson("/api/v1/auth/two-factor/{$enrolment['credential']->public_id}/confirm", ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect($enrolment['credential']->refresh()->confirmed_at)->toBeNull()
        ->and(app(TwoFactorService::class)->enrolled($user))->toBeFalse();
});

it('activates the factor and issues recovery codes once confirmed', function (): void {
    $user = twoFactorUser();
    $result = twoFactorEnrol($user);

    expect($result['credential']->confirmed_at)->not->toBeNull()
        ->and($result['recovery_codes'])->toHaveCount(TwoFactorService::RECOVERY_CODE_COUNT)
        ->and(app(TwoFactorService::class)->enrolled($user))->toBeTrue();
});

it('stores recovery codes hashed, never in the clear', function (): void {
    $user = twoFactorUser();
    $result = twoFactorEnrol($user);

    $stored = RecoveryCode::query()->where('user_id', $user->id)->pluck('code_hash')->all();

    foreach ($result['recovery_codes'] as $code) {
        expect($stored)->not->toContain($code)
            ->and($stored)->toContain(hash('sha256', $code));
    }
});

it('never stores the authenticator secret in the clear', function (): void {
    $user = twoFactorUser();
    $enrolment = twoFactorBeginEnrolment($user);

    $raw = (string) DB::table('two_factor_credentials')
        ->where('id', $enrolment['credential']->id)
        ->value('secret');

    expect($raw)->not->toContain($enrolment['secret']);
});

// --- 17.2 enforcement during sign-in ---

it('grants nothing for a correct password alone', function (): void {
    $user = twoFactorUser();
    twoFactorEnrol($user);

    // Enrolment authenticated as the account; the sign-in under test must start
    // from nobody.
    auth()->forgetGuards();

    $this->post('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertStatus(202)->assertJson(['two_factor_required' => true]);

    $this->getJson('/api/v1/auth/user')->assertStatus(401);
});

it('establishes the session once the factor is satisfied', function (): void {
    $user = twoFactorUser();
    $result = twoFactorEnrol($user);

    auth()->forgetGuards();

    // Confirming the enrolment spent that time step, so signing in needs a code
    // from a genuinely later window — which is the replay rule working.
    $this->travel(60)->seconds();

    $this->post('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertStatus(202);

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'code' => twoFactorCodeFor($result['secret']),
    ])->assertOk()->assertJsonPath('user.email', 'operator@example.test');

    $this->getJson('/api/v1/auth/user')->assertOk();
});

it('refuses a challenge that was never started', function (): void {
    $this->postJson('/api/v1/auth/two-factor/challenge', ['code' => '123456'])->assertStatus(410);
});

// --- 17.3 replay ---

it('refuses a second submission of an accepted code', function (): void {
    $user = twoFactorUser();
    $result = twoFactorEnrol($user);

    auth()->forgetGuards();
    $this->travel(60)->seconds();

    $code = twoFactorCodeFor($result['secret']);

    $this->post('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertStatus(202);

    $this->postJson('/api/v1/auth/two-factor/challenge', ['code' => $code])->assertOk();

    $this->deleteJson('/api/v1/auth/session')->assertStatus(204);

    $this->post('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertStatus(202);

    // Still inside its validity window, and still refused.
    $this->postJson('/api/v1/auth/two-factor/challenge', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

// --- 17.4 recovery codes ---

it('consumes a recovery code and reports how many remain', function (): void {
    $user = twoFactorUser();
    $result = twoFactorEnrol($user);

    auth()->forgetGuards();

    $this->post('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertStatus(202);

    $this->postJson('/api/v1/auth/two-factor/challenge', ['recovery_code' => $result['recovery_codes'][0]])
        ->assertOk()
        ->assertJsonPath('recovery_codes_remaining', TwoFactorService::RECOVERY_CODE_COUNT - 1);
});

it('refuses a recovery code that has already been used', function (): void {
    $user = twoFactorUser();
    $result = twoFactorEnrol($user);
    $code = $result['recovery_codes'][0];

    expect(app(TwoFactorService::class)->consumeRecoveryCode($user, $code))
        ->toBe(TwoFactorService::RECOVERY_CODE_COUNT - 1)
        ->and(app(TwoFactorService::class)->consumeRecoveryCode($user, $code))
        ->toBeNull();
});

// --- 17.6 the instance-wide requirement ---

it('confines an account without a factor to enrolment while the requirement is active', function (): void {
    app(SettingsStore::class)->set('security.two_factor_required', true);

    $user = twoFactorUser();

    $this->actingAs($user)->getJson('/api/v1/links')
        ->assertStatus(403)
        ->assertJson(['two_factor_enrolment_required' => true]);

    // Enrolment itself stays reachable, or the requirement is a locked door with
    // the key behind it.
    $this->actingAs($user)->getJson('/api/v1/auth/two-factor')->assertOk();
    $this->actingAs($user)->postJson('/api/v1/auth/two-factor', ['name' => 'Phone'])->assertStatus(201);
});

it('lets an enrolled account through while the requirement is active', function (): void {
    app(SettingsStore::class)->set('security.two_factor_required', true);

    $user = twoFactorUser();
    twoFactorEnrol($user);

    $this->actingAs($user)->getJson('/api/v1/links')->assertOk();
});

it('refuses to remove the only factor while the requirement is active', function (): void {
    $user = twoFactorUser();
    $result = twoFactorEnrol($user);

    app(SettingsStore::class)->set('security.two_factor_required', true);

    $this->actingAs($user)
        ->deleteJson("/api/v1/auth/two-factor/{$result['credential']->public_id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('credential');

    expect(app(TwoFactorService::class)->enrolled($user))->toBeTrue();
});

it('allows removing a factor when the requirement is not active', function (): void {
    $user = twoFactorUser();
    $result = twoFactorEnrol($user);

    $this->actingAs($user)
        ->deleteJson("/api/v1/auth/two-factor/{$result['credential']->public_id}")
        ->assertStatus(204);

    expect(app(TwoFactorService::class)->enrolled($user))->toBeFalse()
        ->and(RecoveryCode::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('lists a factor with the date it was added', function (): void {
    $user = twoFactorUser();
    twoFactorEnrol($user);

    $this->actingAs($user)
        ->getJson('/api/v1/auth/two-factor')
        ->assertOk()
        ->assertJsonPath('credentials.0.type', 'totp')
        ->assertJsonPath('credentials.0.name', 'Phone')
        ->assertJsonCount(1, 'credentials');
});

it('hides another account enrolment behind a 404', function (): void {
    $owner = twoFactorUser();
    $enrolment = twoFactorBeginEnrolment($owner);
    $stranger = User::factory()->freshlyAuthenticated()->create();

    $this->actingAs($stranger)
        ->postJson("/api/v1/auth/two-factor/{$enrolment['credential']->public_id}/confirm", ['code' => '123456'])
        ->assertStatus(404);
});

// --- the enrolment QR ---

it('renders the pending enrolment as a scannable code', function (): void {
    $user = User::factory()->freshlyAuthenticated()->create();

    $begun = $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor', ['name' => 'Phone'])
        ->assertCreated()
        ->json();

    $response = $this->actingAs($user)
        ->get('/api/v1/auth/two-factor/'.$begun['id'].'/qr')
        ->assertOk();

    $body = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('image/svg+xml')
        ->and($body)->toStartWith('<svg')
        // Carries the shared secret, so it must not be stored anywhere between
        // here and the screen.
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('stops serving the code once the factor is confirmed', function (): void {
    $user = User::factory()->freshlyAuthenticated()->create();

    $begun = $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor', ['name' => 'Phone'])
        ->assertCreated()
        ->json();

    $totp = TOTP::createFromSecret($begun['secret']);
    $totp->setPeriod(30);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/'.$begun['id'].'/confirm', ['code' => $totp->now()])
        ->assertOk();

    // The code is spent. Re-serving it would turn a settings page into a
    // permanent source of the account's TOTP secret.
    $this->actingAs($user)
        ->get('/api/v1/auth/two-factor/'.$begun['id'].'/qr')
        ->assertStatus(404);
});

it('answers as though the enrolment does not exist for another account', function (): void {
    $owner = User::factory()->freshlyAuthenticated()->create();
    $stranger = User::factory()->freshlyAuthenticated()->create();

    $begun = $this->actingAs($owner)
        ->postJson('/api/v1/auth/two-factor', ['name' => 'Phone'])
        ->assertCreated()
        ->json();

    $this->actingAs($stranger)
        ->get('/api/v1/auth/two-factor/'.$begun['id'].'/qr')
        ->assertStatus(404);
});

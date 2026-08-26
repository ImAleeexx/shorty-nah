<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\RequireSetupToken;
use App\Models\Domain;
use App\Models\User;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Setup\SetupToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function setupFlowToken(): string
{
    config(['shortynah.setup_token_path' => storage_path('framework/testing/setup-flow/token')]);

    app()->forgetInstance(SetupToken::class);

    return (string) app(SetupToken::class)->ensure();
}

/**
 * @param  array<string, mixed>  $payload
 */
function setupFlowPost(string $step, array $payload, string $token): TestResponse
{
    return test()->withHeader(RequireSetupToken::HEADER, $token)
        ->postJson("/api/v1/setup/{$step}", $payload);
}

function setupFlowWalkToMail(string $token): void
{
    Http::fake(['*/ping' => Http::response('Ok.')]);

    setupFlowPost('connectivity', [], $token)->assertOk();
    setupFlowPost('administrator', [
        'name' => 'Alex Owner',
        'email' => 'owner@example.test',
        'password' => 'a-long-enough-passphrase-42',
    ], $token)->assertOk();
    setupFlowPost('instance', ['name' => 'Links', 'domain' => 'go.example.test'], $token)->assertOk();
    setupFlowPost('branding', ['accent' => 'oklch(0.55 0.16 250)', 'radius' => 10], $token)->assertOk();
    setupFlowPost('analytics', ['retention_days' => 90, 'bot_filtering' => true], $token)->assertOk();
    setupFlowPost('registration', ['mode' => 'invite'], $token)->assertOk();
}

beforeEach(function (): void {
    $this->markUninstalled();
});

// --- 13.1 Installation state gates the authenticated API ---

it('answers 503 from an authenticated endpoint before installation', function (): void {
    $this->getJson('/api/v1/links')
        ->assertStatus(503)
        ->assertJson(['installed' => false]);
});

it('reports the instance as uninstalled on the public configuration endpoint', function (): void {
    $this->getJson('/api/v1/config')->assertOk()->assertJson(['installed' => false]);
});

// --- 13.8 The claim gate ---

it('accepts no configuration without the setup token', function (): void {
    setupFlowToken();

    $this->postJson('/api/v1/setup/administrator', [
        'name' => 'Stranger',
        'email' => 'stranger@example.test',
        'password' => 'a-long-enough-passphrase-42',
    ])->assertStatus(401);

    expect(User::query()->count())->toBe(0);
});

it('refuses an incorrect setup token', function (): void {
    setupFlowToken();

    $this->withHeader(RequireSetupToken::HEADER, 'wrong')
        ->getJson('/api/v1/setup/state')
        ->assertStatus(401);
});

it('reveals nothing about the instance without the token', function (): void {
    setupFlowToken();

    $response = $this->getJson('/api/v1/setup/state')->assertStatus(401);

    expect($response->json())->toBe(['message' => 'A valid setup token is required.']);
});

it('admits the wizard when the token is presented', function (): void {
    $token = setupFlowToken();

    $this->withHeader(RequireSetupToken::HEADER, $token)
        ->postJson('/api/v1/setup/token')
        ->assertOk()
        ->assertJson(['valid' => true]);
});

// --- 13.2 and 13.9 Connectivity ---

it('reports every configured dependency as healthy and unlocks the next step', function (): void {
    $token = setupFlowToken();

    Http::fake(['*/ping' => Http::response('Ok.')]);

    setupFlowPost('connectivity', [], $token)
        ->assertOk()
        ->assertJson(['healthy' => true, 'next' => 'administrator'])
        ->assertJsonPath('dependencies.0.name', 'postgres');
});

it('names the failing dependency and blocks advancement', function (): void {
    $token = setupFlowToken();

    Http::fake(['*/ping' => Http::response('', 500)]);

    $response = setupFlowPost('connectivity', [], $token)->assertOk();

    expect($response->json('healthy'))->toBeFalse()
        ->and($response->json('next'))->toBe('connectivity');

    $clickhouse = collect($response->json('dependencies'))->firstWhere('name', 'clickhouse');

    expect($clickhouse['healthy'])->toBeFalse()
        ->and($clickhouse['reason'])->toBeString()->not->toBeEmpty();

    // The step never completed, so the one after it is still refused.
    setupFlowPost('administrator', [
        'name' => 'Alex Owner',
        'email' => 'owner@example.test',
        'password' => 'a-long-enough-passphrase-42',
    ], $token)->assertStatus(422)->assertJsonValidationErrors('step');
});

it('ignores a host or connection string supplied by the caller', function (): void {
    $token = setupFlowToken();

    Http::fake(['*/ping' => Http::response('Ok.')]);

    setupFlowPost('connectivity', [
        'host' => 'attacker.example.test',
        'port' => 9999,
        'dsn' => 'pgsql://someone@attacker.example.test:5432/db',
    ], $token)->assertOk()->assertJson(['healthy' => true]);

    // Only the configured ClickHouse host was dialled; the supplied one was not.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'attacker.example.test'));
});

// --- 13.3 The steps ---

it('creates the first account with the owner role', function (): void {
    $token = setupFlowToken();

    Http::fake(['*/ping' => Http::response('Ok.')]);
    setupFlowPost('connectivity', [], $token)->assertOk();

    setupFlowPost('administrator', [
        'name' => 'Alex Owner',
        'email' => 'owner@example.test',
        'password' => 'a-long-enough-passphrase-42',
    ], $token)->assertOk()->assertJson(['next' => 'instance']);

    $user = User::query()->firstOrFail();

    expect($user->role)->toBe(Role::Owner)
        ->and($user->email)->toBe('owner@example.test');
});

it('records the instance identity and registers the primary domain', function (): void {
    $token = setupFlowToken();

    setupFlowWalkToMail($token);

    expect(app(SettingsStore::class)->string('instance.name'))->toBe('Links');

    $domain = Domain::query()->firstOrFail();

    expect($domain->host)->toBe('go.example.test')
        ->and($domain->is_primary)->toBeTrue();
});

it('completes installation when the mail step is skipped', function (): void {
    $token = setupFlowToken();

    setupFlowWalkToMail($token);

    setupFlowPost('mail', ['skip' => true], $token)->assertOk()->assertJson(['next' => null]);

    setupFlowPost('complete', [], $token)
        ->assertOk()
        ->assertJson(['installed' => true])
        ->assertJsonPath('user.role', 'owner');

    $settings = app(SettingsStore::class);

    expect($settings->installed())->toBeTrue()
        // Mail reports itself unconfigured rather than pretending to work.
        ->and($settings->string('mail.host'))->toBeNull();
});

it('refuses a step whose predecessor is outstanding', function (): void {
    $token = setupFlowToken();

    setupFlowPost('instance', ['name' => 'Links', 'domain' => 'go.example.test'], $token)
        ->assertStatus(422)
        ->assertJsonValidationErrors('step');
});

// --- 13.4 Resumption ---

it('resumes at the first incomplete step', function (): void {
    $token = setupFlowToken();

    Http::fake(['*/ping' => Http::response('Ok.')]);
    setupFlowPost('connectivity', [], $token)->assertOk();
    setupFlowPost('administrator', [
        'name' => 'Alex Owner',
        'email' => 'owner@example.test',
        'password' => 'a-long-enough-passphrase-42',
    ], $token)->assertOk();

    // A reload: nothing is carried in the session, so this is a fresh caller.
    $state = $this->withHeader(RequireSetupToken::HEADER, $token)
        ->getJson('/api/v1/setup/state')
        ->assertOk();

    expect($state->json('next'))->toBe('instance')
        ->and($state->json('steps.0.complete'))->toBeTrue()
        ->and($state->json('steps.1.complete'))->toBeTrue()
        ->and($state->json('steps.2.complete'))->toBeFalse();
});

it('refuses to finish while a step is outstanding', function (): void {
    $token = setupFlowToken();

    setupFlowWalkToMail($token);

    setupFlowPost('complete', [], $token)
        ->assertStatus(422)
        ->assertJsonValidationErrors('step');
});

// --- 13.5 Permanent closure ---

it('returns 404 from every setup route once installed', function (): void {
    $token = setupFlowToken();

    setupFlowWalkToMail($token);
    setupFlowPost('mail', ['skip' => true], $token)->assertOk();
    setupFlowPost('complete', [], $token)->assertOk();

    $this->withHeader(RequireSetupToken::HEADER, $token)
        ->getJson('/api/v1/setup/state')
        ->assertStatus(404);
});

it('changes nothing when a setup endpoint is submitted after installation', function (): void {
    $token = setupFlowToken();

    setupFlowWalkToMail($token);
    setupFlowPost('mail', ['skip' => true], $token)->assertOk();
    setupFlowPost('complete', [], $token)->assertOk();

    setupFlowPost('registration', ['mode' => 'open'], $token)->assertStatus(404);

    expect(app(SettingsStore::class)->string('registration.mode'))->toBe('invite');
});

it('invalidates the setup token when installation completes', function (): void {
    $token = setupFlowToken();

    setupFlowWalkToMail($token);
    setupFlowPost('mail', ['skip' => true], $token)->assertOk();
    setupFlowPost('complete', [], $token)->assertOk();

    expect(app(SettingsStore::class)->string(SettingsRegistry::SETUP_TOKEN_HASH))->toBeNull();
});

<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;

function settingsApiAdmin(): User
{
    return User::factory()->create(['role' => Role::Admin]);
}

it('returns the writable settings to an administrator', function (): void {
    $response = $this->actingAs(settingsApiAdmin())
        ->getJson('/api/v1/settings')
        ->assertOk();

    // The keys contain dots, so they are indexed rather than walked as a path.
    $settings = $response->json('settings');

    expect($settings['analytics.retention_days'])->toBe(365)
        ->and($settings['registration.mode'])->toBe('closed');
});

it('never returns a sensitive value, only whether it is configured', function (): void {
    app(SettingsStore::class)->set('mail.password', 'the-smtp-secret');

    $response = $this->actingAs(settingsApiAdmin())
        ->getJson('/api/v1/settings')
        ->assertOk();

    expect($response->json('settings')['mail.password'])->toBeTrue()
        ->and(json_encode($response->json()))->not->toContain('the-smtp-secret');
});

it('hides the settings surface from an account that does not administrate', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Member]))
        ->getJson('/api/v1/settings')
        ->assertStatus(404);
});

it('applies a change without a restart', function (): void {
    $admin = settingsApiAdmin();

    $response = $this->actingAs($admin)
        ->putJson('/api/v1/settings', ['settings' => ['analytics.retention_days' => 90]])
        ->assertOk();

    expect($response->json('settings')['analytics.retention_days'])->toBe(90);

    // Freshly resolved, which is what a subsequent request observes.
    expect(app()->make(SettingsStore::class)->integer('analytics.retention_days'))->toBe(90);
});

it('refuses a setting that is not writable and changes nothing', function (): void {
    $admin = settingsApiAdmin();

    $this->actingAs($admin)
        ->putJson('/api/v1/settings', [
            'settings' => [SettingsRegistry::INSTALLED_AT => 'tampered'],
        ])
        ->assertStatus(422);

    expect(app(SettingsStore::class)->string(SettingsRegistry::INSTALLED_AT))->not->toBe('tampered');
});

it('refuses branding through the settings endpoint, which has its own bounds', function (): void {
    $this->actingAs(settingsApiAdmin())
        ->putJson('/api/v1/settings', ['settings' => ['branding.accent' => 'not-a-colour']])
        ->assertStatus(422);

    expect(app(SettingsStore::class)->string('branding.accent'))->toBe('oklch(0.55 0.16 250)');
});

it('refuses an out-of-bounds value and writes nothing from the same request', function (): void {
    $this->actingAs(settingsApiAdmin())
        ->putJson('/api/v1/settings', [
            'settings' => [
                'analytics.retention_days' => 99999,
                'registration.mode' => 'open',
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.analytics.retention_days']);

    // The valid half of a rejected request must not land either.
    expect(app(SettingsStore::class)->string('registration.mode'))->toBe('closed');
});

it('refuses a value outside the declared enumeration', function (): void {
    $this->actingAs(settingsApiAdmin())
        ->putJson('/api/v1/settings', ['settings' => ['registration.mode' => 'anyone']])
        ->assertStatus(422);
});

it('leaves a configured sensitive value alone when null is submitted', function (): void {
    app(SettingsStore::class)->set('mail.password', 'keep-me');

    $this->actingAs(settingsApiAdmin())
        ->putJson('/api/v1/settings', ['settings' => ['mail.password' => null]])
        ->assertOk();

    expect(app(SettingsStore::class)->string('mail.password'))->toBe('keep-me');
});

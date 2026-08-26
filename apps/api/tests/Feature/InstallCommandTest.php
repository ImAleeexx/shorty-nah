<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Domain;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, string>
 */
function installCommandOptions(): array
{
    return [
        '--admin-name' => 'Alex Owner',
        '--admin-email' => 'owner@example.test',
        '--admin-password' => 'a-long-enough-passphrase-42',
        '--instance-name' => 'Links',
        '--domain' => 'go.example.test',
    ];
}

beforeEach(function (): void {
    $this->markUninstalled();

    Http::fake(['*/ping' => Http::response('Ok.')]);
});

it('installs a fresh instance', function (): void {
    $this->artisan('shortynah:install', installCommandOptions())->assertSuccessful();

    $user = User::query()->firstOrFail();

    expect($user->role)->toBe(Role::Owner)
        ->and(app(SettingsStore::class)->installed())->toBeTrue()
        ->and(app(SettingsStore::class)->string('instance.name'))->toBe('Links')
        ->and(Domain::query()->firstOrFail()->host)->toBe('go.example.test');
});

it('exits non-zero on an instance that is already installed and changes nothing', function (): void {
    $this->markInstalled();

    $this->artisan('shortynah:install', installCommandOptions())->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Domain::query()->count())->toBe(0);
});

it('exits non-zero naming a missing value without a terminal', function (): void {
    $options = installCommandOptions();
    unset($options['--domain']);

    // --no-interaction is what deployment automation runs with, and is the
    // signal the command uses to decide it cannot prompt.
    $options['--no-interaction'] = true;

    $this->artisan('shortynah:install', $options)
        ->expectsOutputToContain('--domain is required')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('refuses a password the policy rejects and changes nothing', function (): void {
    $options = installCommandOptions();
    $options['--admin-password'] = 'short';

    $this->artisan('shortynah:install', $options)->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('applies optional configuration when supplied', function (): void {
    $this->artisan('shortynah:install', installCommandOptions() + [
        '--registration-mode' => 'open',
        '--retention-days' => '90',
        '--radius' => '12',
    ])->assertSuccessful();

    $settings = app(SettingsStore::class);

    expect($settings->string('registration.mode'))->toBe('open')
        ->and($settings->integer('analytics.retention_days'))->toBe(90)
        ->and($settings->integer('branding.radius'))->toBe(12);
});

it('refuses an out-of-bounds radius and changes nothing', function (): void {
    $this->artisan('shortynah:install', installCommandOptions() + ['--radius' => '999'])->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(app(SettingsStore::class)->installed())->toBeFalse();
});

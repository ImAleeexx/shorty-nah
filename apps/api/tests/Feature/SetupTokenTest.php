<?php

declare(strict_types=1);

use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Setup\SetupToken;
use Illuminate\Support\Facades\DB;

function setupTokenPath(): string
{
    return storage_path('framework/testing/setup/token');
}

function setupTokenService(): SetupToken
{
    config(['shortynah.setup_token_path' => setupTokenPath()]);

    app()->forgetInstance(SetupToken::class);

    return app(SetupToken::class);
}

beforeEach(function (): void {
    @unlink(setupTokenPath());

    // First boot is the subject here, so undo the suite's installed default.
    $this->markUninstalled();
});

it('mints a token on first boot and writes it where the host can read it', function (): void {
    $token = setupTokenService()->ensure();

    expect($token)->toBeString()->not->toBeEmpty()
        ->and(file_exists(setupTokenPath()))->toBeTrue()
        ->and(trim((string) file_get_contents(setupTokenPath())))->toBe($token);
});

it('stores only a digest, never the token itself', function (): void {
    $token = (string) setupTokenService()->ensure();

    $stored = (string) DB::table('settings')
        ->where('key', SettingsRegistry::SETUP_TOKEN_HASH)
        ->value('value');

    expect($stored)->not->toContain($token)
        ->and(app(SettingsStore::class)->string(SettingsRegistry::SETUP_TOKEN_HASH))
        ->toBe(hash('sha256', $token));
});

it('keeps the same token across a restart while the instance is uninstalled', function (): void {
    $token = (string) setupTokenService()->ensure();

    // A second boot: the operator already has this value, so minting another
    // would invalidate the one they were given.
    expect(setupTokenService()->ensure())->toBeNull()
        ->and(setupTokenService()->verify($token))->toBeTrue();
});

it('refuses a token that was not issued', function (): void {
    setupTokenService()->ensure();

    expect(setupTokenService()->verify('not-the-token'))->toBeFalse();
});

it('grants nothing once installation has completed', function (): void {
    $token = (string) setupTokenService()->ensure();

    app(SettingsStore::class)->set(SettingsRegistry::INSTALLED_AT, now()->toIso8601String());

    expect(setupTokenService()->verify($token))->toBeFalse();
});

it('removes the credential from the host when installation completes', function (): void {
    setupTokenService()->ensure();

    setupTokenService()->invalidate();

    expect(file_exists(setupTokenPath()))->toBeFalse()
        ->and(setupTokenService()->issued())->toBeFalse();
});

it('does not mint a token on an instance that is already installed', function (): void {
    app(SettingsStore::class)->set(SettingsRegistry::INSTALLED_AT, now()->toIso8601String());

    expect(setupTokenService()->ensure())->toBeNull()
        ->and(file_exists(setupTokenPath()))->toBeFalse();
});

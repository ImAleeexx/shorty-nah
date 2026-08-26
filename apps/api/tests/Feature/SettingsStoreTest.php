<?php

declare(strict_types=1);

use App\Settings\InvalidSettingException;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Settings\UnknownSettingException;
use Illuminate\Support\Facades\DB;

function settings(): SettingsStore
{
    return app(SettingsStore::class);
}

it('returns the declared default until a value is written', function (): void {
    expect(settings()->get('registration.mode'))->toBe('closed')
        ->and(settings()->has('registration.mode'))->toBeFalse();
});

it('observes a written value on the next read without a restart', function (): void {
    settings()->set('instance.name', 'Links');

    // Same process, fresh resolution — what a subsequent request sees.
    expect(app()->make(SettingsStore::class)->get('instance.name'))->toBe('Links');
});

it('invalidates the cache on write', function (): void {
    // Warm the cache, then write past it.
    settings()->get('instance.name');

    DB::table('settings')->updateOrInsert(['key' => 'instance.name'], ['value' => 'Direct write']);

    // Still the cached value, because nothing invalidated it.
    expect(settings()->get('instance.name'))->toBe('Shorty-Nah');

    settings()->flush();

    expect(settings()->get('instance.name'))->toBe('Direct write');
});

it('casts values to their declared type', function (): void {
    settings()->set('branding.radius', '12');
    settings()->set('analytics.bot_filtering', false);

    expect(settings()->get('branding.radius'))->toBe(12)
        ->and(settings()->integer('branding.radius'))->toBe(12)
        ->and(settings()->get('analytics.bot_filtering'))->toBeFalse()
        ->and(settings()->boolean('analytics.bot_filtering'))->toBeFalse();
});

it('rejects a key that is not in the registry', function (): void {
    expect(fn () => settings()->set('registration.mode.evil', 'open'))
        ->toThrow(UnknownSettingException::class, 'is not a known setting');

    expect(fn () => settings()->get('totally.invented'))
        ->toThrow(UnknownSettingException::class);
});

it('rejects a value outside the declared set', function (): void {
    expect(fn () => settings()->set('registration.mode', 'anyone-at-all'))
        ->toThrow(InvalidSettingException::class, 'must be one of');

    expect(settings()->get('registration.mode'))->toBe('closed');
});

it('encrypts a sensitive value at rest', function (): void {
    settings()->set('mail.password', 'hunter2');

    $stored = DB::table('settings')->where('key', 'mail.password')->value('value');

    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain('hunter2')
        ->and(settings()->get('mail.password'))->toBe('hunter2');
});

it('never returns a sensitive value from the full set', function (): void {
    settings()->set('mail.password', 'hunter2');
    settings()->set('geo.maxmind_license_key', 'licence-abc');
    settings()->set('instance.name', 'Links');

    $all = settings()->all();

    expect(json_encode($all))->not->toContain('hunter2')
        ->and(json_encode($all))->not->toContain('licence-abc')
        ->and($all['mail.password'])->toBeTrue()
        ->and($all['instance.name'])->toBe('Links');
});

it('reports a sensitive setting as unconfigured when it has no value', function (): void {
    expect(settings()->all()['mail.password'])->toBeNull();
});

it('treats a value encrypted under a rotated key as unset', function (): void {
    DB::table('settings')->updateOrInsert(
        ['key' => 'mail.password'],
        ['value' => 'not-decryptable-under-this-key'],
    );
    settings()->flush();

    expect(settings()->get('mail.password'))->toBeNull();
});

it('forgets a value and falls back to the default', function (): void {
    settings()->set('instance.name', 'Links');
    settings()->forget('instance.name');

    expect(settings()->get('instance.name'))->toBe('Shorty-Nah');
});

it('reports installation state from the recorded instant', function (): void {
    expect(settings()->installed())->toBeFalse();

    settings()->set(SettingsRegistry::INSTALLED_AT, '2026-08-26T10:00:00Z');

    expect(settings()->installed())->toBeTrue();
});

it('declares no setting as both sensitive and exposed', function (): void {
    foreach (SettingsRegistry::all() as $setting) {
        expect($setting->sensitive && $setting->exposed)->toBeFalse(
            "[{$setting->key}] is declared both sensitive and exposed."
        );
    }
});

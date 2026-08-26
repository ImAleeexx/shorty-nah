<?php

declare(strict_types=1);

use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;

it('is reachable without authentication', function (): void {
    $this->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonStructure([
            'installed',
            'instance' => ['name'],
            'registration' => ['mode'],
            'branding' => ['accent', 'radius', 'typeface', 'logo', 'wordmark', 'favicon'],
        ]);
});

it('reflects written branding so the interface can paint it immediately', function (): void {
    app(SettingsStore::class)->setMany([
        'instance.name' => 'Links',
        'branding.accent' => 'oklch(0.62 0.19 26)',
        'branding.radius' => 12,
        'registration.mode' => 'invite',
    ]);

    $this->getJson('/api/v1/config')
        ->assertOk()
        ->assertJson([
            'instance' => ['name' => 'Links'],
            'registration' => ['mode' => 'invite'],
            'branding' => ['accent' => 'oklch(0.62 0.19 26)', 'radius' => 12],
        ]);
});

it('returns no sensitive value', function (): void {
    app(SettingsStore::class)->setMany([
        'mail.password' => 'hunter2',
        'mail.host' => 'smtp.internal.test',
        'geo.maxmind_license_key' => 'licence-abc',
        'geo.maxmind_account_id' => '424242',
    ]);

    $body = $this->getJson('/api/v1/config')->getContent();

    expect($body)->not->toContain('hunter2')
        ->and($body)->not->toContain('licence-abc')
        ->and($body)->not->toContain('424242')
        ->and($body)->not->toContain('smtp.internal.test');
});

it('returns no operational value', function (): void {
    app(SettingsStore::class)->setMany([
        'analytics.retention_days' => 730,
        'analytics.timezone' => 'Europe/Madrid',
        'redirect.default_mode' => 'interstitial',
        'security.two_factor_required' => true,
    ]);

    $body = $this->getJson('/api/v1/config')->getContent();

    // Retention, timezone, redirect default and the second-factor requirement are
    // operator knobs. A visitor who has not signed in learns none of them.
    expect($body)->not->toContain('730')
        ->and($body)->not->toContain('Europe/Madrid')
        ->and($body)->not->toContain('interstitial')
        ->and($body)->not->toContain('two_factor');
});

it('exposes only keys the registry marks exposed', function (): void {
    /** @var array<string, mixed> $payload */
    $payload = $this->getJson('/api/v1/config')->json();

    $flattened = [];
    array_walk_recursive($payload, function (mixed $value, string|int $key) use (&$flattened): void {
        $flattened[] = (string) $key;
    });

    $private = array_diff(array_keys(SettingsRegistry::all()), array_keys(SettingsRegistry::exposed()));

    foreach ($private as $key) {
        $leaf = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        // installed_at is represented as the boolean `installed`, never as an
        // instant, so its leaf name must not appear either.
        expect($flattened)->not->toContain($leaf, "private setting [{$key}] leaked as [{$leaf}]");
    }
});

it('reports installation state without disclosing when it happened', function (): void {
    app(SettingsStore::class)->set(SettingsRegistry::INSTALLED_AT, '2026-08-26T10:00:00Z');

    $response = $this->getJson('/api/v1/config');

    $response->assertOk()->assertJson(['installed' => true]);

    expect($response->getContent())->not->toContain('2026-08-26');
});

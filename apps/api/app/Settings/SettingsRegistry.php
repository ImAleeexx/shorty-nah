<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * The allowlist of settings this instance recognises.
 *
 * Writes are checked against this registry, so a key that is not defined here
 * cannot be stored — which is what stops request input from inventing
 * configuration.
 *
 * Later phases extend this list. Nothing else about the store changes when they
 * do.
 */
final class SettingsRegistry
{
    public const INSTALLED_AT = 'instance.installed_at';

    public const SETUP_TOKEN_HASH = 'setup.token_hash';

    public const SETUP_COMPLETED_STEPS = 'setup.completed_steps';

    /**
     * Immutable schema, so memoising it holds no request state and is safe under
     * a reused worker.
     *
     * @var array<string, Setting>|null
     */
    private static ?array $definitions = null;

    /**
     * @return array<string, Setting>
     */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $definitions = [];

        foreach (self::declare() as $setting) {
            $definitions[$setting->key] = $setting;
        }

        return self::$definitions = $definitions;
    }

    public static function get(string $key): Setting
    {
        return self::all()[$key] ?? throw UnknownSettingException::for($key);
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * Settings the unauthenticated configuration endpoint may return.
     *
     * @return array<string, Setting>
     */
    public static function exposed(): array
    {
        return array_filter(self::all(), static fn (Setting $s): bool => $s->exposed);
    }

    /**
     * Settings an administrator may change through the settings endpoint.
     *
     * @return array<string, Setting>
     */
    public static function writable(): array
    {
        return array_filter(self::all(), static fn (Setting $s): bool => $s->writable);
    }

    /**
     * Only for tests that need to observe a schema change.
     */
    public static function flush(): void
    {
        self::$definitions = null;
    }

    /**
     * @return list<Setting>
     */
    private static function declare(): array
    {
        return [
            // --- Instance identity ---
            new Setting('instance.name', SettingType::String, 'Shorty-Nah', exposed: true),
            new Setting(self::INSTALLED_AT, SettingType::String),

            // --- First-boot claim gate. The digest lives here; the plaintext
            // exists only in the container log and the host-mounted file, so a
            // leaked backup cannot be used to claim an uninstalled instance. ---
            new Setting(self::SETUP_TOKEN_HASH, SettingType::String, null, sensitive: true),
            new Setting(self::SETUP_COMPLETED_STEPS, SettingType::String),

            // --- Access ---
            new Setting(
                'registration.mode',
                SettingType::String,
                'closed',
                exposed: true,
                allowed: ['closed', 'invite', 'open'],
                writable: true,
            ),
            new Setting('security.two_factor_required', SettingType::Boolean, false, writable: true),

            // --- Branding. Exposed because the interface renders it before
            // anyone signs in, and a late arrival means an unbranded first paint.
            new Setting('branding.accent', SettingType::String, 'oklch(0.55 0.16 250)', exposed: true),
            // Twelve, so a card lands on sixteen and an inner control on eight.
            // The interface derives both from this one value rather than making
            // every corner the same.
            new Setting('branding.radius', SettingType::Integer, 12, exposed: true),
            new Setting('branding.typeface', SettingType::String, 'geist', exposed: true),
            new Setting('branding.logo_path', SettingType::String, null, exposed: true),
            new Setting('branding.wordmark_path', SettingType::String, null, exposed: true),
            new Setting('branding.favicon_path', SettingType::String, null, exposed: true),

            // Shown at the foot of every page, including before anyone signs
            // in, so it is exposed like the rest of branding. Free text: what
            // an operator wants to say there is their business, and an empty
            // value renders nothing at all.
            new Setting(
                'branding.footer_text',
                SettingType::String,
                'Made by ImAleex_ with ❤️',
                exposed: true,
            ),

            // --- Domains. Operational: the addresses a registered domain must
            // resolve to for verification to succeed. ---
            new Setting('domains.instance_addresses', SettingType::String),

            // --- Redirect behaviour ---
            new Setting(
                'redirect.default_mode',
                SettingType::String,
                'direct',
                allowed: ['direct', 'interstitial', 'invisible'],
                writable: true,
            ),
            new Setting('redirect.interstitial_delay_ms', SettingType::Integer, 1200, writable: true),

            // --- Links ---
            new Setting('slug.length', SettingType::Integer, 7),
            new Setting('link.destination_blocklist', SettingType::String),

            // Campaign presets, as JSON in one string setting rather than a
            // table. They are a list of named defaults an operator edits
            // occasionally and the link form reads — no relations, no
            // per-preset authorization, and nothing else joins to them.
            new Setting('link.campaign_presets', SettingType::String, '[]', writable: true),

            // --- Analytics. Operational knobs, deliberately not exposed. ---
            new Setting('analytics.retention_days', SettingType::Integer, 365, writable: true),
            new Setting('analytics.bot_filtering', SettingType::Boolean, true, writable: true),
            new Setting('analytics.timezone', SettingType::String, 'UTC', writable: true),
            new Setting('geo.maxmind_account_id', SettingType::String, null, sensitive: true, writable: true),
            new Setting('geo.maxmind_license_key', SettingType::String, null, sensitive: true, writable: true),

            // --- Outbound mail ---
            new Setting('mail.host', SettingType::String, writable: true),
            new Setting('mail.port', SettingType::Integer, 587, writable: true),
            new Setting('mail.username', SettingType::String, writable: true),
            new Setting('mail.password', SettingType::String, null, sensitive: true, writable: true),
            new Setting('mail.from_address', SettingType::String, writable: true),
        ];
    }
}

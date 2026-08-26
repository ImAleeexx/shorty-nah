<?php

declare(strict_types=1);

namespace App\Clicks;

use App\Settings\SettingsStore;

/**
 * The branding and timing the hold page renders with.
 *
 * Read from the settings store, which is Redis-cached, so this costs one cache
 * read rather than a query. The direct redirect remains the fast path; this mode
 * exists precisely because the operator accepted a page render.
 */
final class InterstitialPresenter
{
    public const MIN_DELAY_MS = 300;

    public const MAX_DELAY_MS = 10000;

    public function __construct(private readonly SettingsStore $settings) {}

    /**
     * @return array{name: string, accent: string, radius: int, logo: ?string, delay_ms: int}
     */
    public function present(): array
    {
        return [
            'name' => $this->settings->string('instance.name') ?? 'Shorty-Nah',
            'accent' => $this->settings->string('branding.accent') ?? 'oklch(0.55 0.16 250)',
            'radius' => $this->settings->integer('branding.radius'),
            'logo' => $this->settings->string('branding.logo_path'),
            'delay_ms' => $this->delay(),
        ];
    }

    public function delay(): int
    {
        $configured = $this->settings->integer('redirect.interstitial_delay_ms');

        if ($configured < self::MIN_DELAY_MS) {
            return self::MIN_DELAY_MS;
        }

        return min($configured, self::MAX_DELAY_MS);
    }
}

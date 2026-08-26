<?php

declare(strict_types=1);

namespace Tests;

use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every feature test but the setup suite exercises an instance somebody has
     * already installed, so that is the default state. Tests covering first boot
     * roll it back with markUninstalled().
     */
    protected function markInstalled(): void
    {
        app(SettingsStore::class)->set(SettingsRegistry::INSTALLED_AT, Carbon::now()->toIso8601String());
    }

    protected function markUninstalled(): void
    {
        app(SettingsStore::class)->forget(SettingsRegistry::INSTALLED_AT);
    }
}

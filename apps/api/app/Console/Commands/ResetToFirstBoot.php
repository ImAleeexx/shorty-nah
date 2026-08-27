<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Setup\SetupProgress;
use App\Setup\SetupToken;
use Illuminate\Console\Command;

/**
 * Returns a development instance to the state a freshly deployed one is in, so
 * the browser suite can walk the wizard against the real thing rather than a
 * mock of it.
 *
 * Destructive, and refused outside development for that reason.
 */
final class ResetToFirstBoot extends Command
{
    protected $signature = 'shortynah:e2e-setup-reset';

    protected $description = 'Return this instance to first boot for the setup browser suite';

    public function handle(SettingsStore $settings, SetupProgress $progress, SetupToken $token): int
    {
        if ($this->getLaravel()->isProduction()) {
            $this->components->error('Refusing to reset a production instance.');

            return self::FAILURE;
        }

        Link::query()->delete();
        Domain::query()->delete();
        User::query()->delete();

        $progress->reset();
        $settings->forget(SettingsRegistry::INSTALLED_AT);
        $settings->forget(SettingsRegistry::SETUP_TOKEN_HASH);

        // Returning to first boot has to mean a clean instance, and this setting
        // in particular: every account was just deleted, so leaving the
        // requirement standing confines the owner the wizard is about to create
        // to enrolling a factor — the wizard's own first request answers 403 and
        // the run cannot proceed. A browser run that fails partway through the
        // second-factor spec leaves exactly that behind.
        $settings->set('security.two_factor_required', false);

        $issued = $token->ensure();

        if ($issued === null) {
            $this->components->error('The setup token could not be issued.');

            return self::FAILURE;
        }

        $this->components->info('Instance returned to first boot.');
        $this->line("  Token written to {$token->path()}");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Settings\SettingsStore;
use App\Setup\SetupToken;
use Illuminate\Console\Command;

/**
 * Emits the first-boot claim gate.
 *
 * Run from the entrypoint on every start. It is idempotent by design: an
 * instance that has already been given a token keeps it, because the operator
 * may be holding the only copy.
 */
final class EnsureSetupToken extends Command
{
    protected $signature = 'shortynah:setup-token';

    protected $description = 'Generate the first-boot setup token if the instance is not installed';

    public function handle(SetupToken $token, SettingsStore $settings): int
    {
        if ($settings->installed()) {
            $this->components->info('Instance is installed; setup is closed and no token exists.');

            return self::SUCCESS;
        }

        $issued = $token->ensure();

        if ($issued === null) {
            $this->components->info('Setup token already issued; it stays valid until installation completes.');
            $this->line("  Recover it from <options=bold>{$token->path()}</> on the host.");

            return self::SUCCESS;
        }

        // Printed as its own block rather than through the logger's formatting:
        // this is the one value an operator must copy out of the log by eye.
        $this->newLine();
        $this->components->info('Setup token generated. It is required to configure this instance.');
        $this->line("  <fg=yellow;options=bold>{$issued}</>");
        $this->line("  Also written to {$token->path()}");
        $this->newLine();

        return self::SUCCESS;
    }
}

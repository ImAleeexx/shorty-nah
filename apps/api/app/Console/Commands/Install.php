<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\RegistrationService;
use App\Branding\BrandingBounds;
use App\Domains\DomainException;
use App\Domains\DomainService;
use App\Rules\StrongPassword;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Setup\DependencyProbe;
use App\Setup\SetupProgress;
use App\Setup\SetupStep;
use App\Setup\SetupToken;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * The wizard's headless equivalent, for deployments that never open a browser.
 *
 * It applies the same configuration through the same services, so an instance
 * provisioned by automation is indistinguishable from one an operator clicked
 * through — including the setup token being spent.
 */
final class Install extends Command
{
    protected $signature = 'shortynah:install
        {--admin-name= : Name of the owner account}
        {--admin-email= : Email address of the owner account}
        {--admin-password= : Password for the owner account}
        {--instance-name= : Display name for this instance}
        {--domain= : The primary short domain}
        {--accent= : Brand accent as an OKLCH colour}
        {--radius= : Corner radius in pixels}
        {--typeface= : Interface typeface}
        {--retention-days= : How long raw click events are kept}
        {--bot-filtering= : Whether known bots are excluded (1 or 0)}
        {--registration-mode= : closed, invite or open}
        {--maxmind-account-id= : MaxMind account identifier}
        {--maxmind-license-key= : MaxMind licence key}
        {--mail-host= : SMTP host}
        {--mail-port= : SMTP port}
        {--mail-username= : SMTP username}
        {--mail-password= : SMTP password}
        {--mail-from= : Address outbound mail is sent from}';

    protected $description = 'Install this instance without the setup wizard';

    /**
     * Values with no sensible default. Option name => prompt shown when a
     * terminal is attached.
     *
     * @var array<string, string>
     */
    private const REQUIRED = [
        'admin-name' => 'Name of the owner account',
        'admin-email' => 'Email address of the owner account',
        'admin-password' => 'Password for the owner account',
        'instance-name' => 'Display name for this instance',
        'domain' => 'The primary short domain',
    ];

    public function handle(
        SettingsStore $settings,
        RegistrationService $registration,
        DomainService $domains,
        DependencyProbe $probe,
        SetupProgress $progress,
        SetupToken $token,
    ): int {
        if ($settings->installed()) {
            $this->components->error('This instance is already installed; nothing was changed.');

            return self::FAILURE;
        }

        $values = $this->collect();

        if ($values === null) {
            return self::FAILURE;
        }

        if (! $this->verifyDependencies($probe)) {
            return self::FAILURE;
        }

        $validation = Validator::make($values, [
            'admin-name' => ['required', 'string', 'max:255'],
            'admin-email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'admin-password' => ['required', 'string', new StrongPassword],
            'instance-name' => ['required', 'string', 'max:80'],
            'domain' => ['required', 'string', 'max:255'],
        ]);

        if ($validation->fails()) {
            $this->components->error('The supplied configuration is not valid; nothing was changed.');

            foreach ($validation->errors()->all() as $message) {
                $this->line("  <fg=red>-</> {$message}");
            }

            return self::FAILURE;
        }

        if (! $this->applyBranding($settings) || ! $this->applyAnalytics($settings) || ! $this->applyRegistration($settings)) {
            return self::FAILURE;
        }

        $registration->createOwner($values['admin-name'], $values['admin-email'], $values['admin-password']);

        $settings->set('instance.name', $values['instance-name']);

        try {
            $domains->register($values['domain']);
        } catch (DomainException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->applyMail($settings);

        foreach (SetupStep::ordered() as $step) {
            $progress->complete($step);
        }

        $settings->set(SettingsRegistry::INSTALLED_AT, Carbon::now()->toIso8601String());

        $token->invalidate();
        $progress->reset();

        $this->components->info("Installed. Sign in as {$values['admin-email']}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>|null
     */
    private function collect(): ?array
    {
        $values = [];

        foreach (self::REQUIRED as $option => $prompt) {
            $value = $this->stringOption($option);

            if ($value === null && $this->input->isInteractive()) {
                $value = $option === 'admin-password'
                    ? (string) $this->secret($prompt)
                    : (string) $this->ask($prompt);

                $value = $value === '' ? null : $value;
            }

            if ($value === null) {
                // Automation gets a name it can act on rather than a prompt it
                // cannot answer.
                $this->components->error("--{$option} is required and was not supplied; nothing was changed.");

                return null;
            }

            $values[$option] = $value;
        }

        return $values;
    }

    private function verifyDependencies(DependencyProbe $probe): bool
    {
        $failed = [];

        foreach ($probe->all() as $status) {
            if (! $status->healthy) {
                $failed[] = "{$status->name}: {$status->reason}";
            }
        }

        if ($failed === []) {
            return true;
        }

        $this->components->error('A dependency is unreachable; nothing was changed.');

        foreach ($failed as $message) {
            $this->line("  <fg=red>-</> {$message}");
        }

        return false;
    }

    private function applyBranding(SettingsStore $settings): bool
    {
        $accent = $this->stringOption('accent');

        if ($accent !== null) {
            if (! BrandingBounds::permitsAccent($accent)) {
                $this->components->error('--accent must be an OKLCH colour, for example oklch(0.55 0.16 250).');

                return false;
            }

            $settings->set('branding.accent', $accent);
        }

        $radius = $this->stringOption('radius');

        if ($radius !== null) {
            if (! is_numeric($radius) || ! BrandingBounds::permitsRadius((int) $radius)) {
                $this->components->error(sprintf(
                    '--radius must be between %d and %d.',
                    BrandingBounds::RADIUS_MIN,
                    BrandingBounds::RADIUS_MAX,
                ));

                return false;
            }

            $settings->set('branding.radius', (int) $radius);
        }

        $typeface = $this->stringOption('typeface');

        if ($typeface !== null) {
            if (! BrandingBounds::permitsTypeface($typeface)) {
                $this->components->error('--typeface must be one of: '.implode(', ', array_keys(BrandingBounds::typefaces())).'.');

                return false;
            }

            $settings->set('branding.typeface', $typeface);
        }

        return true;
    }

    private function applyAnalytics(SettingsStore $settings): bool
    {
        $retention = $this->stringOption('retention-days');

        if ($retention !== null) {
            if (! is_numeric($retention) || (int) $retention < 1 || (int) $retention > 3650) {
                $this->components->error('--retention-days must be between 1 and 3650.');

                return false;
            }

            $settings->set('analytics.retention_days', (int) $retention);
        }

        $filtering = $this->stringOption('bot-filtering');

        if ($filtering !== null) {
            $settings->set('analytics.bot_filtering', filter_var($filtering, FILTER_VALIDATE_BOOLEAN));
        }

        foreach (['maxmind-account-id' => 'geo.maxmind_account_id', 'maxmind-license-key' => 'geo.maxmind_license_key'] as $option => $key) {
            $value = $this->stringOption($option);

            if ($value !== null) {
                $settings->set($key, $value);
            }
        }

        return true;
    }

    private function applyRegistration(SettingsStore $settings): bool
    {
        $mode = $this->stringOption('registration-mode');

        if ($mode === null) {
            return true;
        }

        if (! in_array($mode, ['closed', 'invite', 'open'], true)) {
            $this->components->error('--registration-mode must be closed, invite or open.');

            return false;
        }

        $settings->set('registration.mode', $mode);

        return true;
    }

    private function applyMail(SettingsStore $settings): void
    {
        foreach ([
            'mail-host' => 'mail.host',
            'mail-username' => 'mail.username',
            'mail-password' => 'mail.password',
            'mail-from' => 'mail.from_address',
        ] as $option => $key) {
            $value = $this->stringOption($option);

            if ($value !== null) {
                $settings->set($key, $value);
            }
        }

        $port = $this->stringOption('mail-port');

        if ($port !== null && is_numeric($port)) {
            $settings->set('mail.port', (int) $port);
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}

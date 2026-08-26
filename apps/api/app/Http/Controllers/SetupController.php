<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\AuthenticationService;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The first-boot wizard.
 *
 * Every route here is already behind two guards: the flow is closed once the
 * instance is installed, and nothing is accepted without the setup token. What
 * this class adds is order — a step cannot be submitted while an earlier one is
 * outstanding, so an operator cannot reach the finish line without an owner
 * account.
 */
final class SetupController
{
    public function state(SettingsStore $settings, SetupProgress $progress, DomainService $domains): JsonResponse
    {
        return new JsonResponse([
            'installed' => false,
            'steps' => array_map(
                static fn (SetupStep $step): array => [
                    'step' => $step->value,
                    'complete' => $progress->isComplete($step),
                    'skippable' => $step->skippable(),
                ],
                SetupStep::ordered(),
            ),
            'next' => $progress->next()?->value,
            'values' => [
                'instance_name' => $settings->string('instance.name'),
                'domain' => $domains->primary()?->host,
                'accent' => $settings->string('branding.accent'),
                'radius' => $settings->integer('branding.radius'),
                'typeface' => $settings->string('branding.typeface'),
                'retention_days' => $settings->integer('analytics.retention_days'),
                'bot_filtering' => $settings->boolean('analytics.bot_filtering'),
                'registration_mode' => $settings->string('registration.mode'),
                'mail_host' => $settings->string('mail.host'),
                'mail_port' => $settings->integer('mail.port'),
                'mail_username' => $settings->string('mail.username'),
                'mail_from_address' => $settings->string('mail.from_address'),
            ],
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Exchanging the token for nothing but a yes. The wizard calls this to tell
     * a mistyped token from a working one before it collects anything.
     */
    public function token(): JsonResponse
    {
        return new JsonResponse(['valid' => true], headers: ['Cache-Control' => 'no-store']);
    }

    public function connectivity(DependencyProbe $probe, SetupProgress $progress): JsonResponse
    {
        $statuses = $probe->all();

        $healthy = true;

        foreach ($statuses as $status) {
            $healthy = $healthy && $status->healthy;
        }

        if ($healthy) {
            $progress->complete(SetupStep::Connectivity);
        }

        return new JsonResponse([
            'healthy' => $healthy,
            'dependencies' => array_map(
                static fn ($status): array => $status->toArray(),
                $statuses,
            ),
            // An unreachable datastore is a blocked step, not a failed request:
            // the operator fixes the environment and asks again.
            'next' => $healthy ? $progress->next()?->value : SetupStep::Connectivity->value,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function administrator(
        Request $request,
        RegistrationService $registration,
        SetupProgress $progress,
    ): JsonResponse {
        $this->guardOrder(SetupStep::Administrator, $progress);

        /** @var array{name: string, email: string, password: string} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', new StrongPassword],
        ]);

        $registration->createOwner($input['name'], $input['email'], $input['password']);

        $progress->complete(SetupStep::Administrator);

        return $this->accepted($progress);
    }

    public function instance(
        Request $request,
        SettingsStore $settings,
        DomainService $domains,
        SetupProgress $progress,
    ): JsonResponse {
        $this->guardOrder(SetupStep::Instance, $progress);

        /** @var array{name: string, domain: string} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $settings->set('instance.name', $input['name']);

        $existing = $domains->primary();

        if ($existing === null || $existing->host !== $input['domain']) {
            try {
                $domains->register($input['domain']);
            } catch (DomainException $e) {
                throw ValidationException::withMessages(['domain' => $e->getMessage()]);
            }
        }

        $progress->complete(SetupStep::Instance);

        return $this->accepted($progress);
    }

    public function branding(Request $request, SettingsStore $settings, SetupProgress $progress): JsonResponse
    {
        $this->guardOrder(SetupStep::Branding, $progress);

        /** @var array{accent?: string, radius?: int, typeface?: string} $input */
        $input = $request->validate([
            'accent' => ['sometimes', 'string', 'max:64'],
            'radius' => ['sometimes', 'integer'],
            'typeface' => ['sometimes', 'string', 'max:64'],
        ]);

        $errors = [];

        if (isset($input['accent']) && ! BrandingBounds::permitsAccent($input['accent'])) {
            $errors['accent'] = 'An accent must be an OKLCH colour, for example oklch(0.55 0.16 250).';
        }

        if (isset($input['radius']) && ! BrandingBounds::permitsRadius($input['radius'])) {
            $errors['radius'] = sprintf(
                'A corner radius must be between %d and %d pixels.',
                BrandingBounds::RADIUS_MIN,
                BrandingBounds::RADIUS_MAX,
            );
        }

        if (isset($input['typeface']) && ! BrandingBounds::permitsTypeface($input['typeface'])) {
            $errors['typeface'] = 'Choose one of: '.implode(', ', array_keys(BrandingBounds::typefaces())).'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changes = [];

        foreach (['accent' => 'branding.accent', 'radius' => 'branding.radius', 'typeface' => 'branding.typeface'] as $field => $key) {
            if (array_key_exists($field, $input)) {
                $changes[$key] = $input[$field];
            }
        }

        $settings->setMany($changes);

        $progress->complete(SetupStep::Branding);

        return $this->accepted($progress);
    }

    public function analytics(Request $request, SettingsStore $settings, SetupProgress $progress): JsonResponse
    {
        $this->guardOrder(SetupStep::Analytics, $progress);

        /** @var array{retention_days?: int, bot_filtering?: bool, maxmind_account_id?: string|null, maxmind_license_key?: string|null} $input */
        $input = $request->validate([
            'retention_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'bot_filtering' => ['sometimes', 'boolean'],
            'maxmind_account_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'maxmind_license_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $changes = [];

        foreach ([
            'retention_days' => 'analytics.retention_days',
            'bot_filtering' => 'analytics.bot_filtering',
            'maxmind_account_id' => 'geo.maxmind_account_id',
            'maxmind_license_key' => 'geo.maxmind_license_key',
        ] as $field => $key) {
            if (array_key_exists($field, $input)) {
                $changes[$key] = $input[$field];
            }
        }

        $settings->setMany($changes);

        $progress->complete(SetupStep::Analytics);

        return $this->accepted($progress);
    }

    public function registration(Request $request, SettingsStore $settings, SetupProgress $progress): JsonResponse
    {
        $this->guardOrder(SetupStep::Registration, $progress);

        /** @var array{mode: string} $input */
        $input = $request->validate([
            'mode' => ['required', 'string', 'in:closed,invite,open'],
        ]);

        $settings->set('registration.mode', $input['mode']);

        $progress->complete(SetupStep::Registration);

        return $this->accepted($progress);
    }

    public function mail(Request $request, SettingsStore $settings, SetupProgress $progress): JsonResponse
    {
        $this->guardOrder(SetupStep::Mail, $progress);

        /** @var array{skip?: bool, host?: string|null, port?: int|null, username?: string|null, password?: string|null, from_address?: string|null} $input */
        $input = $request->validate([
            'skip' => ['sometimes', 'boolean'],
            'host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from_address' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
        ]);

        // Skipping is a real answer, not an absence of one: the step is recorded
        // complete so the wizard advances, and mail simply reports itself
        // unconfigured wherever a feature needs it.
        if (($input['skip'] ?? false) !== true) {
            $changes = [];

            foreach ([
                'host' => 'mail.host',
                'port' => 'mail.port',
                'username' => 'mail.username',
                'password' => 'mail.password',
                'from_address' => 'mail.from_address',
            ] as $field => $key) {
                if (array_key_exists($field, $input)) {
                    $changes[$key] = $input[$field];
                }
            }

            $settings->setMany($changes);
        }

        $progress->complete(SetupStep::Mail);

        return $this->accepted($progress);
    }

    public function complete(
        Request $request,
        SettingsStore $settings,
        SetupProgress $progress,
        SetupToken $token,
        RegistrationService $registration,
        AuthenticationService $auth,
    ): JsonResponse {
        $outstanding = $progress->next();

        if ($outstanding !== null) {
            throw ValidationException::withMessages([
                'step' => "The {$outstanding->value} step has not been completed.",
            ]);
        }

        $owner = $registration->owner();

        if ($owner === null) {
            // Belt and braces: the administrator step is a predecessor of every
            // later one, so this is unreachable unless the account was deleted
            // mid-wizard.
            throw ValidationException::withMessages([
                'step' => 'The administrator step has not been completed.',
            ]);
        }

        $settings->set(SettingsRegistry::INSTALLED_AT, Carbon::now()->toIso8601String());

        // Ordered after the install marker on purpose: if anything fails between
        // the two, the instance is installed and the token is already refused by
        // the flow's own guard.
        $token->invalidate();
        $progress->reset();

        $auth->establishSession($request, $owner);

        return new JsonResponse([
            'installed' => true,
            'user' => [
                'id' => $owner->public_id,
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => $owner->role->value,
            ],
        ], headers: ['Cache-Control' => 'no-store']);
    }

    private function guardOrder(SetupStep $step, SetupProgress $progress): void
    {
        $outstanding = $progress->firstOutstandingPredecessor($step);

        if ($outstanding !== null) {
            throw ValidationException::withMessages([
                'step' => "The {$outstanding->value} step must be completed first.",
            ]);
        }
    }

    private function accepted(SetupProgress $progress): JsonResponse
    {
        return new JsonResponse([
            'next' => $progress->next()?->value,
        ], headers: ['Cache-Control' => 'no-store']);
    }
}

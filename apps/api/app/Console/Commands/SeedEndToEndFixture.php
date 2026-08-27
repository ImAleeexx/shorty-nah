<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\DomainRegistry;
use App\Domains\DomainService;
use App\Enums\Role;
use App\Models\Domain;
use App\Models\Link;
use App\Models\RecoveryCode;
use App\Models\TwoFactorCredential;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Seeds the fixture the browser suite drives.
 *
 * Chrome resolves any *.localhost name to loopback, which is what lets a browser
 * reach a short domain through the edge without editing /etc/hosts.
 */
final class SeedEndToEndFixture extends Command
{
    protected $signature = 'shortynah:e2e-fixture';

    protected $description = 'Seed the domain, branding and links the browser suite expects';

    public const HOST = 'go.localhost';

    public const INTERSTITIAL_SLUG = 'e2ehold1';

    public const DIRECT_SLUG = 'e2edrct1';

    public const PASSKEY_EMAIL = 'passkey@example.test';

    public const PASSKEY_PASSWORD = 'a second quiet lantern drifts';

    public function handle(
        SettingsStore $settings,
        DomainService $domains,
        DomainRegistry $registry,
    ): int {
        if ($this->getLaravel()->isProduction()) {
            $this->components->error('This fixture is for development only.');

            return self::FAILURE;
        }

        $settings->setMany([
            'instance.name' => 'Externalia Links',
            'branding.accent' => 'oklch(0.62 0.19 26)',
            'branding.radius' => 14,
            'redirect.interstitial_delay_ms' => 600,
            // Cleared alongside the credentials below. The fixture already
            // removed every second factor and left the requirement standing,
            // which is the one combination that locks the suite out of its own
            // instance — and a run that fails partway through the second-factor
            // spec leaves exactly that behind.
            'security.two_factor_required' => false,
            // Asserted by the auth spec, so the fixture states it rather than
            // inheriting whatever the last run happened to leave.
            'registration.mode' => 'closed',
        ]);

        $domain = Domain::query()->where('host', self::HOST)->first()
            ?? $domains->register(self::HOST);

        $domain->forceFill(['verified_at' => now()])->save();
        $registry->flush();

        $owner = User::query()->where('email', 'e2e@example.test')->first();

        if (! $owner instanceof User) {
            $owner = new User;
            $owner->forceFill([
                'name' => 'End-to-end operator',
                'email' => 'e2e@example.test',
                'password' => 'a quiet lantern drifts',
                'role' => Role::Owner->value,
                'password_changed_at' => now(),
            ])->save();
        }

        // The passkey suite gets its own account. Enrolling a second factor on
        // the shared one would send every other browser test to a challenge
        // screen, and they run in parallel.
        $passkey = User::query()->where('email', self::PASSKEY_EMAIL)->first();

        if (! $passkey instanceof User) {
            $passkey = new User;
            $passkey->forceFill([
                'name' => 'Passkey operator',
                'email' => self::PASSKEY_EMAIL,
                'password' => self::PASSKEY_PASSWORD,
                'role' => Role::Member->value,
                'password_changed_at' => now(),
            ])->save();
        }

        // Returned to having no second factor, so the suite starts from the same
        // place however the last run ended.
        RecoveryCode::query()->where('user_id', $passkey->id)->delete();
        TwoFactorCredential::query()->where('user_id', $passkey->id)->delete();
        RecoveryCode::query()->where('user_id', $owner->id)->delete();
        TwoFactorCredential::query()->where('user_id', $owner->id)->delete();

        // The destination is on loopback, which destination validation refuses for
        // good reason. These rows are written directly because the fixture
        // exercises the interstitial, not that validation.
        //
        // It points at the sign-in screen rather than the interface root because
        // the root redirects an unauthenticated viewer, and a destination that
        // redirects makes "did the browser arrive" unanswerable.
        $this->fixture($domain, $owner, self::INTERSTITIAL_SLUG, 'interstitial');
        $this->fixture($domain, $owner, self::DIRECT_SLUG, null);

        $this->components->info(sprintf(
            'Seeded http://%s:8080/%s and /%s',
            self::HOST,
            self::INTERSTITIAL_SLUG,
            self::DIRECT_SLUG,
        ));

        return self::SUCCESS;
    }

    private function fixture(Domain $domain, User $owner, string $slug, ?string $mode): void
    {
        $existing = Link::withTrashed()
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->first();

        $link = $existing instanceof Link ? $existing : new Link;

        $link->forceFill([
            'public_id' => $existing instanceof Link ? $existing->public_id : (string) Str::ulid(),
            'domain_id' => $domain->id,
            'slug' => $slug,
            'destination' => 'http://localhost:8080/sign-in',
            'redirect_mode' => $mode,
            'created_by' => $owner->id,
            'deleted_at' => null,
        ])->save();
    }
}

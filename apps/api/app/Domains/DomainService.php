<?php

declare(strict_types=1);

namespace App\Domains;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registration, promotion and removal of short domains.
 */
final class DomainService
{
    public function __construct(private readonly DomainRegistry $registry) {}

    public function register(string $host, ?User $actor = null): Domain
    {
        $normalised = Domain::normaliseHost($host);

        if ($normalised === '' || ! $this->looksLikeHostname($normalised)) {
            throw new DomainException('That is not a valid hostname.');
        }

        if (Domain::query()->where('host', $normalised)->exists()) {
            throw new DomainException('That domain is already registered.');
        }

        $domain = new Domain;
        $domain->forceFill([
            'host' => $normalised,
            'verification_token' => Str::random(32),
            'created_by' => $actor?->id,
            // The first domain on a fresh instance becomes primary, so an
            // instance is never left without one.
            'is_primary' => ! Domain::query()->exists(),
        ])->save();

        $this->registry->flush();

        return $domain->refresh();
    }

    /**
     * Exactly one domain is primary. Promotion runs in a transaction so a
     * failure cannot leave the instance with none or two.
     */
    public function promoteToPrimary(Domain $domain): void
    {
        if (! $domain->isVerified()) {
            throw new DomainException('Only a verified domain may be primary.');
        }

        DB::transaction(function () use ($domain): void {
            Domain::query()->where('is_primary', true)->update(['is_primary' => false]);

            $domain->forceFill(['is_primary' => true])->save();
        });

        $this->registry->flush();
    }

    public function primary(): ?Domain
    {
        $primary = Domain::query()->where('is_primary', true)->first();

        return $primary instanceof Domain ? $primary : null;
    }

    public function delete(Domain $domain, bool $confirmLinkDeletion = false): void
    {
        if ($domain->is_primary) {
            throw new DomainException(
                'The primary domain cannot be deleted. Promote another verified domain first.'
            );
        }

        $linkCount = $this->linkCount($domain);

        if ($linkCount > 0 && ! $confirmLinkDeletion) {
            throw new DomainException(sprintf(
                'That domain still has %d link(s). Confirm deletion to remove them.',
                $linkCount,
            ));
        }

        $domain->delete();

        $this->registry->flush();
    }

    /**
     * Links arrive in a later phase. Counting through the schema rather than a
     * relation keeps this guard correct the moment the table exists, instead of
     * silently reporting zero forever.
     */
    public function linkCount(Domain $domain): int
    {
        if (! DB::getSchemaBuilder()->hasTable('links')) {
            return 0;
        }

        return DB::table('links')->where('domain_id', $domain->id)->count();
    }

    private function looksLikeHostname(string $host): bool
    {
        if (mb_strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }

        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $host) === 1;
    }
}

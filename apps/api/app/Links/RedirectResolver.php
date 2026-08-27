<?php

declare(strict_types=1);

namespace App\Links;

use App\Enums\RedirectMode;
use App\Settings\SettingsStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;

/**
 * Turns an incoming host and slug into something to serve.
 *
 * Three properties matter here more than anywhere else in the product:
 *
 *   1. A cache hit performs no database query. This is the only route a stranger
 *      can drive at volume, and it must survive the database being slow.
 *   2. A miss for a slug that does not exist is cached too. Without that, walking
 *      the slug space is a denial-of-service against Postgres.
 *   3. A cold popular slug resolves once. A newly published link can take
 *      thousands of simultaneous requests; without a lock they all query at once.
 */
final class RedirectResolver
{
    /**
     * Positive entries carry a bounded lifetime as a backstop. Invalidation is
     * driven by model events, so this only matters if one is ever missed.
     */
    private const POSITIVE_TTL_SECONDS = 3600;

    /**
     * Short, because a slug that does not exist today may be created in a moment
     * and the operator should not have to wait for it.
     */
    private const NEGATIVE_TTL_SECONDS = 30;

    private const ABSENT = 'absent';

    private const LOCK_SECONDS = 5;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsStore $settings,
    ) {}

    /**
     * Null means nothing is served for this host and slug, whether because the
     * slug does not exist, the domain is unknown, or the domain is unverified.
     * The caller cannot tell which, and neither can a visitor.
     */
    public function resolve(string $host, string $slug): ?ResolvedLink
    {
        $key = LinkCache::key($host, $slug);
        $cached = $this->cache->get($key);

        if ($cached === self::ABSENT) {
            return null;
        }

        if (is_array($cached)) {
            return ResolvedLink::fromArray($cached);
        }

        return $this->resolveCold($key, $host, $slug);
    }

    private function resolveCold(string $key, string $host, string $slug): ?ResolvedLink
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->loadAndCache($key, $host, $slug);
        }

        $lock = $store->lock($key.':lock', self::LOCK_SECONDS);

        if (! $lock->get()) {
            // Someone else is loading it. Wait briefly for their result rather
            // than issuing a duplicate query.
            try {
                $lock->block(self::LOCK_SECONDS);
            } catch (LockTimeoutException) {
                return $this->loadAndCache($key, $host, $slug);
            }

            try {
                $cached = $this->cache->get($key);

                if ($cached === self::ABSENT) {
                    return null;
                }

                if (is_array($cached)) {
                    return ResolvedLink::fromArray($cached);
                }

                return $this->loadAndCache($key, $host, $slug);
            } finally {
                $lock->release();
            }
        }

        try {
            // Another worker may have populated it between the miss and the lock.
            $cached = $this->cache->get($key);

            if ($cached === self::ABSENT) {
                return null;
            }

            if (is_array($cached)) {
                return ResolvedLink::fromArray($cached);
            }

            return $this->loadAndCache($key, $host, $slug);
        } finally {
            $lock->release();
        }
    }

    private function loadAndCache(string $key, string $host, string $slug): ?ResolvedLink
    {
        $row = $this->query($host, $slug);

        if ($row === null) {
            $this->cache->put($key, self::ABSENT, self::NEGATIVE_TTL_SECONDS);

            return null;
        }

        $resolved = $this->hydrate($row);

        $this->cache->put($key, $resolved->toArray(), self::POSITIVE_TTL_SECONDS);

        return $resolved;
    }

    /**
     * A single joined query, using the query builder rather than Eloquent: this
     * runs once per cold slug and has no need for models, events or casts.
     */
    private function query(string $host, string $slug): ?object
    {
        $row = DB::table('links')
            ->join('domains', 'domains.id', '=', 'links.domain_id')
            ->whereNull('links.deleted_at')
            // An unverified domain serves nothing, so it must not resolve here
            // either.
            ->whereNotNull('domains.verified_at')
            ->where('domains.host', mb_strtolower($host))
            ->where('links.slug', $slug)
            ->select([
                'links.id',
                'links.domain_id',
                'links.public_id',
                'links.destination',
                'links.redirect_mode',
                'links.password_hash',
                'links.expires_at',
                'links.max_clicks',
                'links.click_count',
                'links.disabled_at',
                'links.referrer_policy',
            ])
            ->first();

        return is_object($row) ? $row : null;
    }

    /**
     * The rules for one link, in position order.
     *
     * A second query on the cold path only. It is not a join because a link with
     * five rules would multiply the row above by five and every column with it,
     * and the cold path runs once an hour per slug.
     *
     * @return list<RoutingRule>
     */
    private function rulesFor(int $linkId): array
    {
        $rows = DB::table('link_rules')
            ->where('link_id', $linkId)
            ->orderBy('position')
            ->select(['kind', 'value', 'destination'])
            ->get();

        $rules = [];

        foreach ($rows as $row) {
            /** @var object{kind: string, value: string, destination: string} $row */
            $rule = RoutingRule::fromArray([
                'kind' => $row->kind,
                'value' => $row->value,
                'destination' => $row->destination,
            ]);

            if ($rule instanceof RoutingRule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private function hydrate(object $row): ResolvedLink
    {
        /** @var object{id: int, domain_id: int, public_id: string, destination: string, redirect_mode: ?string, password_hash: ?string, expires_at: ?string, max_clicks: ?int, click_count: int, disabled_at: ?string, referrer_policy: ?string} $row */
        $mode = $row->redirect_mode === null
            ? $this->instanceDefaultMode()
            : (RedirectMode::tryFrom($row->redirect_mode) ?? $this->instanceDefaultMode());

        return new ResolvedLink(
            id: (int) $row->id,
            domainId: (int) $row->domain_id,
            publicId: (string) $row->public_id,
            destination: (string) $row->destination,
            mode: $mode,
            requiresPassword: $row->password_hash !== null,
            disabled: $row->disabled_at !== null,
            expiresAtTimestamp: $this->timestamp($row->expires_at),
            maxClicks: $row->max_clicks === null ? null : (int) $row->max_clicks,
            persistedClicks: (int) $row->click_count,
            referrerPolicy: $row->referrer_policy,
            rules: $this->rulesFor((int) $row->id),
        );
    }

    /**
     * Nested ternaries around ?: are a compile error in PHP 8.4, and a named
     * method reads better than the parenthesised version anyway.
     */
    private function timestamp(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }

    private function instanceDefaultMode(): RedirectMode
    {
        return RedirectMode::tryFrom((string) $this->settings->get('redirect.default_mode'))
            ?? RedirectMode::Direct;
    }

    /**
     * The stored password hash, fetched only when a visitor actually submits a
     * password. Keeping it out of the cache entry means a cache dump cannot be
     * turned into an offline attack on every protected link at once.
     */
    public function passwordHashFor(int $linkId): ?string
    {
        $hash = DB::table('links')->where('id', $linkId)->whereNull('deleted_at')->value('password_hash');

        return is_string($hash) ? $hash : null;
    }
}

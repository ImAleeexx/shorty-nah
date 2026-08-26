<?php

declare(strict_types=1);

use App\Domains\DomainException;
use App\Domains\DomainService;
use App\Enums\RedirectMode;
use App\Links\LinkCache;
use App\Links\LinkException;
use App\Links\LinkService;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Support\Facades\Hash;

function links(): LinkService
{
    return app(LinkService::class);
}

function member(): User
{
    return User::factory()->member()->create();
}

function primaryDomain(string $host = 'go.example.com'): Domain
{
    return Domain::factory()->primary()->create(['host' => $host]);
}

// --- 7.5 constraints ---

it('uses the primary domain when none is named', function (): void {
    $primary = primaryDomain();
    Domain::factory()->create(['host' => 'other.example.com']);

    $link = links()->create(['destination' => 'https://example.org'], member());

    expect($link->domain_id)->toBe($primary->id);
});

it('refuses to create a link on an unverified domain', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'pending.example.com']);

    expect(fn () => links()->create(['destination' => 'https://example.org', 'domain' => $domain], member()))
        ->toThrow(LinkException::class, 'not verified');
});

it('hashes a link password and never stores it in the clear', function (): void {
    $link = links()->create([
        'destination' => 'https://example.org',
        'domain' => primaryDomain(),
        'password' => 'a quiet lantern drifts',
    ], member());

    expect($link->password_hash)->not->toBe('a quiet lantern drifts')
        ->and(Hash::check('a quiet lantern drifts', (string) $link->password_hash))->toBeTrue()
        ->and($link->requiresPassword())->toBeTrue()
        ->and(json_encode($link->toArray()))->not->toContain('lantern');
});

it('refuses an expiry in the past', function (): void {
    expect(fn () => links()->create([
        'destination' => 'https://example.org',
        'domain' => primaryDomain(),
        'expires_at' => now()->subHour()->toIso8601String(),
    ], member()))->toThrow(LinkException::class, 'must be in the future');
});

it('refuses a click limit below one', function (): void {
    expect(fn () => links()->create([
        'destination' => 'https://example.org',
        'domain' => primaryDomain(),
        'max_clicks' => 0,
    ], member()))->toThrow(LinkException::class, 'at least 1');
});

it('reports a link as unresolvable once a constraint is met', function (string $state): void {
    $link = match ($state) {
        'expired' => Link::factory()->expired()->create(),
        'disabled' => Link::factory()->disabled()->create(),
        'limit' => Link::factory()->limitReached()->create(),
    };

    expect($link->resolvable())->toBeFalse();
})->with(['expired', 'disabled', 'limit']);

it('reports an ordinary link as resolvable', function (): void {
    expect(Link::factory()->create()->resolvable())->toBeTrue();
});

it('keeps a disabled link and its analytics rather than removing it', function (): void {
    $link = links()->create(['destination' => 'https://example.org', 'domain' => primaryDomain()], member());

    links()->update($link, ['disabled' => true]);

    expect($link->refresh()->isDisabled())->toBeTrue()
        ->and(Link::query()->whereKey($link->id)->exists())->toBeTrue();
});

// --- per-link mode with instance-default fallback ---

it('follows the instance default when the link chose no mode', function (): void {
    app(SettingsStore::class)->set('redirect.default_mode', 'interstitial');

    $link = links()->create(['destination' => 'https://example.org', 'domain' => primaryDomain()], member());

    expect($link->redirect_mode)->toBeNull()
        ->and(links()->effectiveMode($link))->toBe(RedirectMode::Interstitial);
});

it('moves with the default when the operator changes it', function (): void {
    app(SettingsStore::class)->set('redirect.default_mode', 'direct');
    $link = links()->create(['destination' => 'https://example.org', 'domain' => primaryDomain()], member());

    expect(links()->effectiveMode($link))->toBe(RedirectMode::Direct);

    app(SettingsStore::class)->set('redirect.default_mode', 'interstitial');

    expect(links()->effectiveMode($link->refresh()))->toBe(RedirectMode::Interstitial);
});

it('keeps an explicit mode when the default changes', function (): void {
    app(SettingsStore::class)->set('redirect.default_mode', 'direct');

    $link = links()->create([
        'destination' => 'https://example.org',
        'domain' => primaryDomain(),
        'redirect_mode' => 'interstitial',
    ], member());

    app(SettingsStore::class)->set('redirect.default_mode', 'direct');

    expect(links()->effectiveMode($link))->toBe(RedirectMode::Interstitial);
});

it('refuses an unknown redirect mode', function (): void {
    expect(fn () => links()->create([
        'destination' => 'https://example.org',
        'domain' => primaryDomain(),
        'redirect_mode' => 'teleport',
    ], member()))->toThrow(LinkException::class, 'direct or interstitial');
});

// --- 7.6 cache invalidation driven by model events ---

it('evicts the cache when a link is created', function (): void {
    $domain = primaryDomain();
    $cache = app(LinkCache::class);

    // Prime a negative entry as the redirect path would for an unknown slug.
    cache()->forever(LinkCache::key($domain->host, 'newslug'), 'absent');

    links()->create(['destination' => 'https://example.org', 'domain' => $domain, 'slug' => 'newslug'], member());

    expect($cache->has($domain->host, 'newslug'))->toBeFalse();
});

it('evicts the cache when a destination changes', function (): void {
    $domain = primaryDomain();
    $link = links()->create(['destination' => 'https://one.example.org', 'domain' => $domain, 'slug' => 'edited'], member());

    cache()->forever(LinkCache::key($domain->host, 'edited'), 'stale');

    links()->update($link, ['destination' => 'https://two.example.org']);

    expect(app(LinkCache::class)->has($domain->host, 'edited'))->toBeFalse()
        ->and($link->refresh()->destination)->toBe('https://two.example.org');
});

it('evicts both the old and the new key when a slug changes', function (): void {
    $domain = primaryDomain();
    $link = links()->create(['destination' => 'https://example.org', 'domain' => $domain, 'slug' => 'before'], member());

    cache()->forever(LinkCache::key($domain->host, 'before'), 'stale');
    cache()->forever(LinkCache::key($domain->host, 'after'), 'stale');

    links()->update($link, ['slug' => 'after']);

    $cache = app(LinkCache::class);

    expect($cache->has($domain->host, 'before'))->toBeFalse()
        ->and($cache->has($domain->host, 'after'))->toBeFalse();
});

it('evicts the cache when a link is deleted', function (): void {
    $domain = primaryDomain();
    $link = links()->create(['destination' => 'https://example.org', 'domain' => $domain, 'slug' => 'goingaway'], member());

    cache()->forever(LinkCache::key($domain->host, 'goingaway'), 'stale');

    $link->delete();

    expect(app(LinkCache::class)->has($domain->host, 'goingaway'))->toBeFalse();
});

it('keys the cache by host and slug together', function (): void {
    // Slugs are unique per domain, so a slug-only key would serve one domain's
    // link on another.
    expect(LinkCache::key('a.example.com', 'launch'))
        ->not->toBe(LinkCache::key('b.example.com', 'launch'));
});

// --- 7.11 the domain deletion guard, now that links exist ---

it('refuses to delete a domain that still has links', function (): void {
    primaryDomain();
    $second = Domain::factory()->create(['host' => 'second.example.com']);

    links()->create(['destination' => 'https://example.org', 'domain' => $second, 'slug' => 'keeper'], member());

    expect(fn () => app(DomainService::class)->delete($second))
        ->toThrow(DomainException::class, 'still has 1 link');

    expect(Domain::query()->whereKey($second->id)->exists())->toBeTrue();
});

it('deletes a domain with links when deletion is confirmed', function (): void {
    primaryDomain();
    $second = Domain::factory()->create(['host' => 'second.example.com']);

    links()->create(['destination' => 'https://example.org', 'domain' => $second, 'slug' => 'goes2'], member());

    app(DomainService::class)->delete($second, confirmLinkDeletion: true);

    expect(Domain::query()->whereKey($second->id)->exists())->toBeFalse();
});

it('reports how many links a domain holds', function (): void {
    primaryDomain();
    $second = Domain::factory()->create(['host' => 'second.example.com']);
    $user = member();

    foreach (['aaa1', 'bbb2', 'ccc3'] as $slug) {
        links()->create(['destination' => 'https://example.org', 'domain' => $second, 'slug' => $slug], $user);
    }

    expect(fn () => app(DomainService::class)->delete($second))
        ->toThrow(DomainException::class, 'still has 3 link');
});

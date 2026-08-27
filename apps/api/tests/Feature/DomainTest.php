<?php

declare(strict_types=1);

use App\Domains\DnsResolver;
use App\Domains\DomainException;
use App\Domains\DomainRegistry;
use App\Domains\DomainService;
use App\Domains\DomainVerifier;
use App\Enums\Role;
use App\Models\Domain;
use App\Models\User;
use App\Settings\SettingsStore;

/**
 * DNS in a test suite is neither deterministic nor fast, so resolution is faked.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @param array<string, list<string>> $records */
    public function __construct(private array $records = []) {}

    /** @param list<string> $addresses */
    public function set(string $host, array $addresses): void
    {
        $this->records[$host] = $addresses;
    }

    public function addressesFor(string $host): array
    {
        return $this->records[$host] ?? [];
    }
}

function dns(): FakeDnsResolver
{
    if (! app()->bound('test.dns')) {
        $fake = new FakeDnsResolver;
        app()->instance('test.dns', $fake);
        app()->instance(DnsResolver::class, $fake);
    }

    /** @var FakeDnsResolver $fake */
    $fake = app('test.dns');

    return $fake;
}

function domains(): DomainService
{
    return app(DomainService::class);
}

beforeEach(function (): void {
    dns();
    // Routable addresses, not RFC 5737 documentation ranges — those are blocked
    // by NetworkAddress because nothing is ever hosted on them.
    app(SettingsStore::class)->set('domains.instance_addresses', '93.184.216.34,2606:2800:220::10');
    app(DomainRegistry::class)->flush();
});

// --- 6.1 registration, primary designation, deletion guards ---

it('makes the first registered domain primary', function (): void {
    $first = domains()->register('go.example.com');
    $second = domains()->register('links.example.com');

    expect($first->is_primary)->toBeTrue()
        ->and($second->is_primary)->toBeFalse();
});

it('normalises the host', function (string $input, string $expected): void {
    expect(Domain::normaliseHost($input))->toBe($expected);
})->with([
    ['GO.Example.COM', 'go.example.com'],
    ['https://go.example.com', 'go.example.com'],
    ['http://go.example.com/some/path', 'go.example.com'],
    ['go.example.com.', 'go.example.com'],
    ['  go.example.com  ', 'go.example.com'],
]);

it('refuses a duplicate host regardless of case', function (): void {
    domains()->register('go.example.com');

    expect(fn () => domains()->register('GO.Example.com'))
        ->toThrow(DomainException::class, 'already registered');
});

it('refuses a hostname that is not one', function (string $host): void {
    expect(fn () => domains()->register($host))->toThrow(DomainException::class, 'not a valid hostname');
})->with(['localhost', 'no-dot', '-leading.example.com', 'trailing-.example.com', '']);

it('refuses to delete the primary domain', function (): void {
    $primary = domains()->register('go.example.com');

    expect(fn () => domains()->delete($primary))
        ->toThrow(DomainException::class, 'primary domain cannot be deleted');

    expect(Domain::query()->count())->toBe(1);
});

it('deletes a non-primary domain', function (): void {
    domains()->register('go.example.com');
    $second = domains()->register('links.example.com');

    domains()->delete($second);

    expect(Domain::query()->count())->toBe(1);
});

it('keeps exactly one primary after promotion', function (): void {
    $first = domains()->register('go.example.com');
    $second = domains()->register('links.example.com');
    $second->forceFill(['verified_at' => now()])->save();

    domains()->promoteToPrimary($second);

    expect(Domain::query()->where('is_primary', true)->count())->toBe(1)
        ->and($second->refresh()->is_primary)->toBeTrue()
        ->and($first->refresh()->is_primary)->toBeFalse();
});

it('refuses to promote an unverified domain', function (): void {
    domains()->register('go.example.com');
    $second = domains()->register('links.example.com');

    expect(fn () => domains()->promoteToPrimary($second))
        ->toThrow(DomainException::class, 'Only a verified domain may be primary');
});

// --- 6.2 verification ---

it('verifies a domain resolving to the instance', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    dns()->set('go.example.com', ['93.184.216.34']);

    $result = app(DomainVerifier::class)->verify($domain);

    expect($result->verified)->toBeTrue()
        ->and($domain->refresh()->isVerified())->toBeTrue()
        ->and($domain->last_failure)->toBeNull();
});

it('refuses a domain resolving somewhere else', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    dns()->set('go.example.com', ['8.8.8.8']);

    $result = app(DomainVerifier::class)->verify($domain);

    expect($result->verified)->toBeFalse()
        ->and($result->failure)->toContain('does not resolve to this instance')
        ->and($domain->refresh()->isVerified())->toBeFalse();
});

it('refuses a domain that does not resolve', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);

    expect(app(DomainVerifier::class)->verify($domain)->failure)->toContain('does not resolve');
});

it('refuses a domain resolving inside the operator network', function (string $address): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    dns()->set('go.example.com', [$address]);

    // Even a matching private address is refused: a name that cannot be reached
    // publicly should not receive a certificate.
    expect(app(DomainVerifier::class)->verify($domain)->failure)->toContain('non-public address');
})->with(['127.0.0.1', '10.0.0.5', '169.254.169.254', '172.17.0.2', '::1']);

it('refuses verification when the instance address is unconfigured', function (): void {
    app(SettingsStore::class)->forget('domains.instance_addresses');

    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    dns()->set('go.example.com', ['93.184.216.34']);

    expect(app(DomainVerifier::class)->verify($domain)->failure)->toContain('not configured');
});

it('does not serve links from an unverified domain', function (): void {
    $unverified = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    $verified = Domain::factory()->create(['host' => 'links.example.com']);

    expect($unverified->servesLinks())->toBeFalse()
        ->and($verified->servesLinks())->toBeTrue()
        ->and(app(DomainRegistry::class)->serves('go.example.com'))->toBeFalse()
        ->and(app(DomainRegistry::class)->serves('links.example.com'))->toBeTrue();
});

it('serves links once verification succeeds', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    dns()->set('go.example.com', ['93.184.216.34']);

    expect(app(DomainRegistry::class)->serves('go.example.com'))->toBeFalse();

    app(DomainVerifier::class)->verify($domain);

    // The registry cache must have been invalidated, or a newly verified domain
    // would keep being refused.
    expect(app(DomainRegistry::class)->serves('go.example.com'))->toBeTrue();
});

// --- 6.3 certificate authorization ---

it('approves a certificate for a verified domain', function (): void {
    Domain::factory()->create(['host' => 'go.example.com']);

    $this->get('/api/internal/tls-authorize?domain=go.example.com')->assertOk();
});

it('declines a certificate for an unknown hostname', function (): void {
    Domain::factory()->create(['host' => 'go.example.com']);

    $this->get('/api/internal/tls-authorize?domain=attacker.example.net')->assertStatus(404);
});

it('declines a certificate for a registered but unverified domain', function (): void {
    Domain::factory()->unverified()->create(['host' => 'go.example.com']);

    $this->get('/api/internal/tls-authorize?domain=go.example.com')->assertStatus(404);
});

it('rejects an authorization request with no hostname', function (): void {
    $this->get('/api/internal/tls-authorize')->assertStatus(400);
});

it('matches the hostname case-insensitively', function (): void {
    Domain::factory()->create(['host' => 'go.example.com']);

    $this->get('/api/internal/tls-authorize?domain=GO.Example.COM')->assertOk();
});

it('answers from cache without querying the database', function (): void {
    Domain::factory()->create(['host' => 'go.example.com']);

    // Warm the registry.
    $this->get('/api/internal/tls-authorize?domain=go.example.com')->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get('/api/internal/tls-authorize?domain=go.example.com')->assertOk();

    // This runs before a certificate exists, so it must not depend on the
    // database being reachable.
    expect(DB::getQueryLog())->toBeEmpty();
});

// Registering, verifying and promoting a domain are instance configuration, not
// credential changes. Deletion is the one the contract names, and it keeps the
// challenge — it destroys links, which is not recoverable.
it('registers a domain with a session older than the re-authentication window', function (): void {
    $admin = User::factory()->staleAuthentication()->create(['role' => Role::Admin]);

    $this->actingAs($admin)
        ->postJson('/api/v1/domains', ['host' => 'stale.example.test'])
        ->assertCreated();
});

it('still challenges a stale session before deleting a domain', function (): void {
    $admin = User::factory()->staleAuthentication()->create(['role' => Role::Admin]);
    $domain = Domain::factory()->create();

    $this->actingAs($admin)
        ->deleteJson('/api/v1/domains/'.$domain->public_id)
        ->assertStatus(423);
});

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

/**
 * DNS in a test suite is neither deterministic nor fast, so resolution is faked.
 */
final class FakeDnsResolver implements DnsResolver
{
    /**
     * @param  array<string, list<string>>  $records
     * @param  array<string, list<string>>  $txt
     */
    public function __construct(private array $records = [], private array $txt = []) {}

    /** @param list<string> $addresses */
    public function set(string $host, array $addresses): void
    {
        $this->records[$host] = $addresses;
    }

    /** @param list<string> $values */
    public function setTxt(string $name, array $values): void
    {
        $this->txt[$name] = $values;
    }

    public function addressesFor(string $host): array
    {
        return $this->records[$host] ?? [];
    }

    public function txtRecordsFor(string $name): array
    {
        return $this->txt[$name] ?? [];
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
//
// Control is proven by DNS, with a TXT record rather than by where the host
// resolves: a host fronted by a CDN resolves to the CDN's addresses and could
// never match this instance's, and nothing is fetched from the operator's host,
// which would be an SSRF surface pointed at an attacker-chosen name.

it('names the record an operator must publish', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);

    expect($domain->verificationRecordName())->toBe('_shortynah-verify.go.example.com')
        ->and($domain->verificationRecordValue())->toBe('shortynah-verify='.$domain->verification_token);
});

it('verifies a domain whose DNS carries its verification record', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    dns()->setTxt($domain->verificationRecordName(), [$domain->verificationRecordValue()]);

    $result = app(DomainVerifier::class)->verify($domain);

    expect($result->verified)->toBeTrue()
        ->and($domain->refresh()->isVerified())->toBeTrue()
        ->and($domain->last_failure)->toBeNull();
});

it('finds the record among unrelated ones at the same name', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    dns()->setTxt($domain->verificationRecordName(), [
        'v=spf1 -all',
        '  '.$domain->verificationRecordValue().'  ',
    ]);

    expect(app(DomainVerifier::class)->verify($domain)->verified)->toBeTrue();
});

it('refuses a domain with no verification record', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);

    $result = app(DomainVerifier::class)->verify($domain);

    expect($result->verified)->toBeFalse()
        ->and($result->failure)->toContain('No verification record')
        ->and($result->failure)->toContain('_shortynah-verify.go.example.com')
        ->and($domain->refresh()->isVerified())->toBeFalse()
        ->and($domain->last_checked_at)->not->toBeNull();
});

it('refuses a verification record carrying another token', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    dns()->setTxt($domain->verificationRecordName(), ['shortynah-verify=someoneelsestoken']);

    $result = app(DomainVerifier::class)->verify($domain);

    expect($result->verified)->toBeFalse()
        ->and($result->failure)->toContain('does not carry this domain');
});

it('does not consult where the host resolves', function (): void {
    // A CDN-fronted host resolves to the CDN. That must not matter.
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);
    dns()->set('go.example.com', ['10.0.0.5']);
    dns()->setTxt($domain->verificationRecordName(), [$domain->verificationRecordValue()]);

    expect(app(DomainVerifier::class)->verify($domain)->verified)->toBeTrue();
});

it('presents the record to an administrator', function (): void {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $domain = Domain::factory()->unverified()->create(['host' => 'go.example.com']);

    $this->actingAs($admin)
        ->getJson('/api/v1/domains')
        ->assertOk()
        ->assertJsonPath('domains.0.verification.type', 'TXT')
        ->assertJsonPath('domains.0.verification.name', '_shortynah-verify.go.example.com')
        ->assertJsonPath('domains.0.verification.value', 'shortynah-verify='.$domain->verification_token);
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
    dns()->setTxt($domain->verificationRecordName(), [$domain->verificationRecordValue()]);

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

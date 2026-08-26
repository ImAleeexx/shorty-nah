<?php

declare(strict_types=1);

use App\Domains\DnsResolver;
use App\Domains\DomainRegistry;
use App\Links\DestinationValidator;
use App\Links\LinkException;
use App\Models\Domain;
use App\Settings\SettingsStore;

final class DestinationDns implements DnsResolver
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

function destinationDns(): DestinationDns
{
    if (! app()->bound('test.destination-dns')) {
        $fake = new DestinationDns;
        app()->instance('test.destination-dns', $fake);
        app()->instance(DnsResolver::class, $fake);
    }

    /** @var DestinationDns $fake */
    $fake = app('test.destination-dns');

    return $fake;
}

function destinations(): DestinationValidator
{
    return app(DestinationValidator::class);
}

beforeEach(function (): void {
    destinationDns();
});

it('accepts an ordinary https destination', function (): void {
    expect(destinations()->validate('https://example.org/page?a=1'))->toBe('https://example.org/page?a=1');
});

it('accepts http', function (): void {
    expect(destinations()->validate('http://example.org'))->toBe('http://example.org');
});

it('refuses a scheme a browser will not navigate to', function (string $destination): void {
    expect(fn () => destinations()->validate($destination))
        ->toThrow(LinkException::class, 'must use http or https');
})->with([
    'javascript:alert(1)',
    'data:text/html;base64,PHNjcmlwdD4=',
    'file:///etc/passwd',
    'vbscript:msgbox(1)',
    'ftp://example.org',
]);

it('refuses a destination that is not absolute', function (string $destination): void {
    expect(fn () => destinations()->validate($destination))
        ->toThrow(LinkException::class, 'absolute URL');
})->with(['example.org', '/relative/path', '//protocol-relative.example.org']);

it('refuses an empty destination', function (): void {
    expect(fn () => destinations()->validate('   '))->toThrow(LinkException::class, 'destination is required');
});

it('refuses a destination pointing back at a short domain of this instance', function (): void {
    Domain::factory()->create(['host' => 'go.example.com']);
    app(DomainRegistry::class)->flush();

    expect(fn () => destinations()->validate('https://go.example.com/abc123'))
        ->toThrow(LinkException::class, 'point back at this instance');
});

it('refuses a destination pointing at the interface domain', function (): void {
    config()->set('shortynah.domain', 'admin.example.com');

    expect(fn () => destinations()->validate('https://admin.example.com/dashboard'))
        ->toThrow(LinkException::class, 'point back at this instance');
});

it('refuses a blocklisted host and its subdomains', function (string $destination): void {
    app(SettingsStore::class)->set('link.destination_blocklist', 'malware.test, phishing.example');

    expect(fn () => destinations()->validate($destination))
        ->toThrow(LinkException::class, 'not permitted on this instance');
})->with([
    'https://malware.test/x',
    'https://sub.malware.test/x',
    'https://deep.sub.phishing.example/x',
]);

it('does not treat a suffix match as a blocked subdomain', function (): void {
    app(SettingsStore::class)->set('link.destination_blocklist', 'malware.test');

    // notmalware.test merely ends with the string; it is a different domain.
    expect(destinations()->validate('https://notmalware.test/x'))->toBe('https://notmalware.test/x');
});

// --- 7.10 addresses the public cannot reach ---

it('refuses a literal private or infrastructure address', function (string $host): void {
    expect(fn () => destinations()->validate("https://{$host}/x"))
        ->toThrow(LinkException::class, 'not publicly reachable');
})->with(['127.0.0.1', '10.0.0.5', '172.17.0.2', '192.168.1.1', '169.254.169.254', '100.64.0.1']);

it('refuses a hostname resolving to a private address', function (string $address): void {
    destinationDns()->set('internal.example.org', [$address]);

    expect(fn () => destinations()->validate('https://internal.example.org/x'))
        ->toThrow(LinkException::class, 'resolves to an address that is not publicly reachable');
})->with(['127.0.0.1', '10.0.0.5', '169.254.169.254', '::1', 'fd00::1']);

it('refuses when only one of several answers is private', function (): void {
    // A single private answer among public ones is still a way in.
    destinationDns()->set('mixed.example.org', ['93.184.216.34', '10.0.0.5']);

    expect(fn () => destinations()->validate('https://mixed.example.org/x'))
        ->toThrow(LinkException::class, 'not publicly reachable');
});

it('accepts a hostname resolving to a public address', function (): void {
    destinationDns()->set('good.example.org', ['93.184.216.34']);

    expect(destinations()->validate('https://good.example.org/x'))->toBe('https://good.example.org/x');
});

it('accepts a hostname that does not resolve yet', function (): void {
    // Nothing has been shown to be private, and the domain may simply be new.
    expect(destinations()->validate('https://brand-new.example.org/x'))->toBe('https://brand-new.example.org/x');
});

it('refuses the cloud metadata address however it is written', function (string $host): void {
    expect(fn () => destinations()->validate("https://{$host}/latest/meta-data/"))
        ->toThrow(LinkException::class, 'not publicly reachable');
})->with(['169.254.169.254', '[::ffff:169.254.169.254]']);

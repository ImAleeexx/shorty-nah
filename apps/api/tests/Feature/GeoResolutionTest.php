<?php

declare(strict_types=1);

use App\Clicks\GeoLookup;
use App\Clicks\GeoResolver;

/**
 * Exercises the real GeoLite2 databases.
 *
 * Every other geo test runs against a stub, which proves the pipeline handles
 * what the resolver returns but not that the resolver returns anything. This
 * one reads the databases the sidecar downloads, and skips rather than fails
 * where they are absent — an instance without a MaxMind licence is a supported
 * configuration, not a broken one.
 */
function geoDatabasePath(): string
{
    return (string) config('shortynah.geoip_path');
}

function geoResolverAgainstRealDatabases(): GeoResolver
{
    return new GeoLookup(geoDatabasePath());
}

beforeEach(function (): void {
    if (! file_exists(geoDatabasePath().'/GeoLite2-City.mmdb')) {
        test()->markTestSkipped('No GeoLite2 databases; set MAXMIND_LICENSE_KEY and run the sidecar.');
    }
});

it('reports the databases as present', function (): void {
    expect(geoResolverAgainstRealDatabases()->missingDatabases())->toBeFalse();
});

it('resolves a public address to its country and network', function (): void {
    $result = geoResolverAgainstRealDatabases()->lookup('8.8.8.8');

    expect($result->isKnown())->toBeTrue()
        ->and($result->countryCode)->toBe('US')
        // Google's own network. The number is what the datacenter filter reads.
        ->and($result->asn)->toBe(15169);
});

it('resolves a city where the database has one', function (): void {
    $result = geoResolverAgainstRealDatabases()->lookup('193.238.111.1');

    expect($result->countryCode)->toBe('UA')
        ->and($result->city)->not->toBeEmpty();
});

it('returns a network even where the country is unknown', function (): void {
    // An anycast resolver has a network but no meaningful location, and a
    // partial answer is more useful than discarding the whole lookup.
    $result = geoResolverAgainstRealDatabases()->lookup('1.1.1.1');

    expect($result->asn)->toBe(13335);
});

it('treats a private address as unknown rather than guessing', function (): void {
    foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '172.16.0.1'] as $address) {
        expect(geoResolverAgainstRealDatabases()->lookup($address)->isKnown())
            ->toBeFalse($address);
    }
});

it('treats malformed input as unknown rather than throwing', function (?string $address): void {
    expect(geoResolverAgainstRealDatabases()->lookup($address)->isKnown())->toBeFalse();
})->with([null, '', 'not-an-address', '999.999.999.999']);

<?php

declare(strict_types=1);

namespace App\Clicks;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Throwable;

/**
 * Resolves geography and network from an address using local MaxMind databases.
 *
 * In-process and file-backed on purpose: a hosted lookup would put a network call
 * in the enrichment path, add a rate limit and a bill, and send visitor addresses
 * to a third party — which is the opposite of why the address is discarded
 * immediately afterwards.
 *
 * Readers are held open because opening one per event would dominate the cost of
 * enriching a batch, and reopened when the file changes so the geoipupdate
 * sidecar's refresh is picked up without a restart.
 */
final class GeoLookup implements GeoResolver
{
    private ?Reader $cityReader = null;

    private ?Reader $asnReader = null;

    private ?int $cityMtime = null;

    private ?int $asnMtime = null;

    public function __construct(private readonly string $databasePath) {}

    public function isAvailable(): bool
    {
        return $this->cityPath() !== null || $this->asnPath() !== null;
    }

    public function missingDatabases(): bool
    {
        return ! $this->isAvailable();
    }

    public function lookup(?string $address): GeoResult
    {
        if ($address === null || $address === '') {
            return GeoResult::unknown();
        }

        return new GeoResult(
            ...array_merge(
                $this->city($address),
                $this->asn($address),
            )
        );
    }

    /**
     * @return array{countryCode: string, region: string, city: string}
     */
    private function city(string $address): array
    {
        $reader = $this->reader('city');

        if ($reader === null) {
            return ['countryCode' => '', 'region' => '', 'city' => ''];
        }

        try {
            $record = $reader->city($address);

            return [
                'countryCode' => (string) ($record->country->isoCode ?? ''),
                'region' => (string) ($record->mostSpecificSubdivision->name ?? ''),
                'city' => (string) ($record->city->name ?? ''),
            ];
        } catch (AddressNotFoundException) {
            // A valid address the database simply does not cover. The click is
            // still counted, with geography marked unknown.
            return ['countryCode' => '', 'region' => '', 'city' => ''];
        } catch (Throwable) {
            return ['countryCode' => '', 'region' => '', 'city' => ''];
        }
    }

    /**
     * @return array{asn: int, organisation: string}
     */
    private function asn(string $address): array
    {
        $reader = $this->reader('asn');

        if ($reader === null) {
            return ['asn' => 0, 'organisation' => ''];
        }

        try {
            $record = $reader->asn($address);

            return [
                'asn' => (int) ($record->autonomousSystemNumber ?? 0),
                'organisation' => (string) ($record->autonomousSystemOrganization ?? ''),
            ];
        } catch (AddressNotFoundException) {
            return ['asn' => 0, 'organisation' => ''];
        } catch (Throwable) {
            return ['asn' => 0, 'organisation' => ''];
        }
    }

    private function reader(string $kind): ?Reader
    {
        $path = $kind === 'city' ? $this->cityPath() : $this->asnPath();

        if ($path === null) {
            return null;
        }

        $mtime = @filemtime($path);
        $mtime = $mtime === false ? null : $mtime;

        $current = $kind === 'city' ? $this->cityReader : $this->asnReader;
        $known = $kind === 'city' ? $this->cityMtime : $this->asnMtime;

        if ($current !== null && $known === $mtime) {
            return $current;
        }

        try {
            $reader = new Reader($path);
        } catch (Throwable) {
            return null;
        }

        if ($kind === 'city') {
            $this->cityReader = $reader;
            $this->cityMtime = $mtime;
        } else {
            $this->asnReader = $reader;
            $this->asnMtime = $mtime;
        }

        return $reader;
    }

    private function cityPath(): ?string
    {
        return $this->existing(['GeoLite2-City.mmdb', 'GeoIP2-City.mmdb']);
    }

    private function asnPath(): ?string
    {
        return $this->existing(['GeoLite2-ASN.mmdb', 'GeoIP2-ASN.mmdb']);
    }

    /**
     * @param  list<string>  $names
     */
    private function existing(array $names): ?string
    {
        foreach ($names as $name) {
            $path = rtrim($this->databasePath, '/').'/'.$name;

            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }
}

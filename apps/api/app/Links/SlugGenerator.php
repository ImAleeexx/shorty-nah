<?php

declare(strict_types=1);

namespace App\Links;

use App\Models\Domain;
use App\Settings\SettingsStore;
use Random\RandomException;

/**
 * Produces unguessable slugs.
 *
 * Randomness comes from a cryptographically secure source, not from a counter or
 * a hash of the destination: on a private instance a derivable slug lets anyone
 * walk the whole corpus.
 */
final class SlugGenerator
{
    /**
     * Enough attempts to make collision failure vanishingly unlikely at any
     * sensible occupancy, while still terminating if the slug space really is
     * full.
     */
    public const MAX_ATTEMPTS = 12;

    public const DEFAULT_LENGTH = 7;

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly SlugAvailability $availability,
    ) {}

    public function generateFor(Domain $domain): string
    {
        $length = $this->configuredLength();

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $slug = $this->random($length);

            if (ReservedSlugs::contains($slug)) {
                continue;
            }

            if (! $this->availability->isTaken($domain, $slug)) {
                return $slug;
            }
        }

        throw new SlugExhaustedException(sprintf(
            'Could not find a free slug of length %d for [%s] in %d attempts. Increase the configured slug length.',
            $length,
            $domain->host,
            self::MAX_ATTEMPTS,
        ));
    }

    public function configuredLength(): int
    {
        $configured = $this->settings->integer('slug.length');

        if ($configured < SlugAlphabet::MIN_LENGTH || $configured > SlugAlphabet::MAX_LENGTH) {
            return self::DEFAULT_LENGTH;
        }

        return $configured;
    }

    /**
     * Drawing an index directly rather than reducing a random byte: 58 does not
     * divide 256, so a modulo would bias the first 24 characters of the
     * alphabet.
     */
    private function random(int $length): string
    {
        $alphabet = SlugAlphabet::GENERATED;
        $max = SlugAlphabet::generatedSize() - 1;
        $slug = '';

        for ($i = 0; $i < $length; $i++) {
            try {
                $slug .= $alphabet[random_int(0, $max)];
            } catch (RandomException $e) {
                throw new SlugExhaustedException('No secure randomness available for slug generation.', previous: $e);
            }
        }

        return $slug;
    }
}

<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Length plus a bundled list of commonly used passwords.
 *
 * The list is bundled rather than checked against a breach API — even a
 * k-anonymity endpoint sends a signal about our users to a third party, and it
 * fails in an air-gapped install. This catches the realistic case offline.
 */
final class StrongPassword implements ValidationRule
{
    public const MINIMUM_LENGTH = 12;

    /** @var list<string>|null */
    private static ?array $common = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.')->translate();

            return;
        }

        if (mb_strlen($value) < self::MINIMUM_LENGTH) {
            $fail(sprintf('The :attribute must be at least %d characters.', self::MINIMUM_LENGTH))->translate();

            return;
        }

        if (in_array(mb_strtolower($value), self::common(), true)) {
            $fail('The :attribute is among the most commonly used passwords. Choose something else.')->translate();
        }
    }

    /**
     * @return list<string>
     */
    private static function common(): array
    {
        if (self::$common !== null) {
            return self::$common;
        }

        $path = resource_path('security/common-passwords.txt');
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            return self::$common = [];
        }

        return self::$common = array_values(array_filter(
            array_map(
                static fn (string $line): string => mb_strtolower(trim($line)),
                explode("\n", $contents),
            ),
            static fn (string $line): bool => $line !== '',
        ));
    }
}

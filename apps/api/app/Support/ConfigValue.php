<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Narrows a raw configuration value to a usable type.
 *
 * Dotenv coerces "true", "null" and numeric strings, so env() is typed as
 * bool|int|string|null. Config files call env() themselves — the framework
 * requires it and Larastan enforces it — and pass the result through here so a
 * misconfigured value fails loudly at boot instead of silently becoming "1".
 */
final class ConfigValue
{
    public static function string(mixed $value, string $name): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new RuntimeException(
            "Configuration value [{$name}] must be a string, got ".get_debug_type($value).'.'
        );
    }
}

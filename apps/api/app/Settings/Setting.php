<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * The definition of one setting.
 *
 * `sensitive` decides whether the value is encrypted at rest and withheld from
 * every response. `exposed` decides whether it reaches the unauthenticated
 * configuration endpoint the interface reads before anyone signs in. The two are
 * mutually exclusive and that is asserted by the registry's own test.
 */
final class Setting
{
    /**
     * @param  list<string>|null  $allowed
     */
    public function __construct(
        public readonly string $key,
        public readonly SettingType $type,
        public readonly string|bool|int|null $default = null,
        public readonly bool $sensitive = false,
        public readonly bool $exposed = false,
        public readonly ?array $allowed = null,
    ) {}
}

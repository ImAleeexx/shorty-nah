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
 *
 * `writable` decides whether the settings endpoint may change it. It defaults to
 * false for the same reason `exposed` does: a setting added later is inert until
 * its definition says otherwise, so forgetting to think about it is safe. Values
 * with their own endpoint — branding, which has bounds to enforce — stay false
 * here so there is only ever one write path with one set of rules.
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
        public readonly bool $writable = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Settings;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Reads and writes instance configuration.
 *
 * Values are cached as one entry rather than one per key, so a request that
 * needs several settings makes a single cache read.
 *
 * Nothing is memoised in a property. Under Octane the same worker serves many
 * requests, and an in-process copy would go stale the moment another worker
 * wrote a value — the cache is the only shared source of truth.
 */
final class SettingsStore
{
    private const CACHE_KEY = 'shortynah:settings';

    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly CacheRepository $cache,
        private readonly Encrypter $encrypter,
    ) {}

    public function get(string $key): string|bool|int|null
    {
        $setting = SettingsRegistry::get($key);
        $stored = $this->stored();

        if (! array_key_exists($key, $stored)) {
            return $setting->default;
        }

        return $this->cast($setting, $stored[$key]);
    }

    public function string(string $key): ?string
    {
        $value = $this->get($key);

        return $value === null ? null : (string) $value;
    }

    public function boolean(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function integer(string $key): int
    {
        return (int) $this->get($key);
    }

    /**
     * Whether a value has actually been set.
     *
     * A cleared setting is stored as a NULL row rather than deleted, so the key
     * being present is not the question. Getting this wrong makes a sensitive
     * setting report itself configured forever after being emptied — mail would
     * authenticate with no password while the interface said otherwise.
     */
    public function has(string $key): bool
    {
        $stored = $this->stored();

        return array_key_exists($key, $stored) && $stored[$key] !== null;
    }

    public function set(string $key, string|bool|int|null $value): void
    {
        $setting = SettingsRegistry::get($key);

        $this->database->table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $this->serialise($setting, $this->validate($setting, $value)), 'updated_at' => Carbon::now()],
        );

        $this->flush();
    }

    /**
     * @param  array<string, string|bool|int|null>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function forget(string $key): void
    {
        SettingsRegistry::get($key);

        $this->database->table('settings')->where('key', $key)->delete();

        $this->flush();
    }

    /**
     * Every known setting with its effective value. Sensitive values are
     * replaced, so this is safe to hand to anything that serialises.
     *
     * @return array<string, string|bool|int|null>
     */
    public function all(): array
    {
        $values = [];

        foreach (SettingsRegistry::all() as $key => $setting) {
            $values[$key] = $setting->sensitive
                ? ($this->has($key) ? true : null)
                : $this->get($key);
        }

        return $values;
    }

    /**
     * The subset the unauthenticated configuration endpoint may return.
     *
     * @return array<string, string|bool|int|null>
     */
    public function exposed(): array
    {
        $values = [];

        foreach (SettingsRegistry::exposed() as $key => $setting) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function installed(): bool
    {
        return $this->get(SettingsRegistry::INSTALLED_AT) !== null;
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     */
    private function stored(): array
    {
        /** @var array<string, string|null> $stored */
        $stored = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            $rows = $this->database->table('settings')->get(['key', 'value']);

            $values = [];

            foreach ($rows as $row) {
                /** @var object{key: string, value: string|null} $row */
                $values[$row->key] = $row->value;
            }

            return $values;
        });

        return $stored;
    }

    private function validate(Setting $setting, string|bool|int|null $value): string|bool|int|null
    {
        if ($value === null) {
            return null;
        }

        $value = match ($setting->type) {
            SettingType::Boolean => (bool) $value,
            SettingType::Integer => (int) $value,
            SettingType::String => (string) $value,
        };

        if ($setting->allowed !== null && ! in_array((string) $value, $setting->allowed, true)) {
            throw new InvalidSettingException(sprintf(
                '[%s] must be one of: %s.',
                $setting->key,
                implode(', ', $setting->allowed),
            ));
        }

        return $value;
    }

    private function serialise(Setting $setting, string|bool|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $plain = match ($setting->type) {
            SettingType::Boolean => $value ? '1' : '0',
            default => (string) $value,
        };

        return $setting->sensitive ? $this->encrypter->encryptString($plain) : $plain;
    }

    private function cast(Setting $setting, ?string $stored): string|bool|int|null
    {
        if ($stored === null) {
            return null;
        }

        if ($setting->sensitive) {
            try {
                $stored = $this->encrypter->decryptString($stored);
            } catch (DecryptException) {
                // A value encrypted under a rotated key is unreadable, not
                // silently wrong. Treating it as unset makes the setting appear
                // unconfigured rather than serving a corrupt value.
                return null;
            }
        }

        return match ($setting->type) {
            SettingType::Boolean => $stored === '1',
            SettingType::Integer => (int) $stored,
            SettingType::String => $stored,
        };
    }
}

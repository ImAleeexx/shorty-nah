<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Settings\Setting;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Settings\SettingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Operator-changeable configuration.
 *
 * The registry is the authority on what exists, what type it is and what may be
 * written, so this controller adds no allowlist of its own — a setting is inert
 * here until its definition says otherwise. Branding is deliberately absent: it
 * has bounds to enforce and its own endpoint, and two write paths with different
 * rules is how one of them ends up being the way round the other.
 */
final class SettingsController
{
    /**
     * Extra constraints that are not expressible as a type or an enumeration.
     *
     * @var array<string, array{min?: int, max?: int}>
     */
    private const BOUNDS = [
        'analytics.retention_days' => ['min' => 1, 'max' => 3650],
        'redirect.interstitial_delay_ms' => ['min' => 0, 'max' => 15000],
        'mail.port' => ['min' => 1, 'max' => 65535],
    ];

    public function show(Request $request, SettingsStore $settings): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        $values = [];

        foreach (SettingsRegistry::writable() as $key => $setting) {
            // Sensitive values are reported as configured or not, never returned.
            $values[$key] = $setting->sensitive
                ? ($settings->has($key) ? true : null)
                : $settings->get($key);
        }

        return new JsonResponse([
            'settings' => $values,
            'schema' => array_map(
                static fn (Setting $setting): array => [
                    'type' => $setting->type->value,
                    'sensitive' => $setting->sensitive,
                    'allowed' => $setting->allowed,
                    'bounds' => self::BOUNDS[$setting->key] ?? null,
                ],
                SettingsRegistry::writable(),
            ),
        ]);
    }

    public function update(Request $request, SettingsStore $settings): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        /** @var array<string, mixed> $input */
        $input = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        /** @var array<string, mixed> $submitted */
        $submitted = $input['settings'];

        $errors = [];
        $changes = [];

        foreach ($submitted as $key => $value) {
            $setting = SettingsRegistry::writable()[$key] ?? null;

            if (! $setting instanceof Setting) {
                // An unknown key and a key that exists but may not be written
                // are one answer: nothing here confirms which settings exist.
                $errors["settings.{$key}"] = 'That setting cannot be changed.';

                continue;
            }

            // A sensitive value is left alone when the caller sends null, because
            // it was never given the current one to send back.
            if ($value === null && $setting->sensitive) {
                continue;
            }

            $problem = $this->problemWith($setting, $value);

            if ($problem !== null) {
                $errors["settings.{$key}"] = $problem;

                continue;
            }

            $changes[$key] = $this->cast($setting, $value);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $settings->setMany($changes);

        return $this->show($request, $settings);
    }

    private function problemWith(Setting $setting, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($setting->type === SettingType::Boolean) {
            return is_bool($value) ? null : 'That must be true or false.';
        }

        if ($setting->type === SettingType::Integer) {
            if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
                return 'That must be a whole number.';
            }

            $bounds = self::BOUNDS[$setting->key] ?? [];
            $number = (int) $value;

            if (isset($bounds['min']) && $number < $bounds['min']) {
                return "That must be at least {$bounds['min']}.";
            }

            if (isset($bounds['max']) && $number > $bounds['max']) {
                return "That must be at most {$bounds['max']}.";
            }

            return null;
        }

        if (! is_string($value)) {
            return 'That must be text.';
        }

        if ($setting->allowed !== null && ! in_array($value, $setting->allowed, true)) {
            return 'Choose one of: '.implode(', ', $setting->allowed).'.';
        }

        if (mb_strlen($value) > 255) {
            return 'That is too long.';
        }

        return null;
    }

    private function cast(Setting $setting, mixed $value): string|bool|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($setting->type) {
            SettingType::Boolean => (bool) $value,
            SettingType::Integer => (int) $value,
            SettingType::String => (string) $value,
        };
    }

    private function administrates(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->administrates();
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    public const PASSWORD = 'correct-horse-battery-staple';

    /**
     * Hashing once per process keeps Argon2id out of every test's critical path.
     */
    private static ?string $password = null;

    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'role' => Role::Member->value,
            'password' => self::$password ??= Hash::make(self::PASSWORD),
            'password_changed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => Role::Owner->value]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => Role::Admin->value]);
    }

    public function member(): static
    {
        return $this->state(fn (): array => ['role' => Role::Member->value]);
    }

    public function viewer(): static
    {
        return $this->state(fn (): array => ['role' => Role::Viewer->value]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['disabled_at' => now()]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    /**
     * Authenticated long enough ago that a sensitive operation must re-prompt.
     */
    public function staleAuthentication(): static
    {
        return $this->state(fn (): array => ['last_authenticated_at' => now()->subHours(4)]);
    }

    public function freshlyAuthenticated(): static
    {
        return $this->state(fn (): array => ['last_authenticated_at' => now()]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Domain>
 */
final class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'host' => Str::lower(fake()->unique()->domainName()),
            'is_primary' => false,
            'verified_at' => now(),
            'verification_token' => Str::random(32),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['verified_at' => null]);
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['is_primary' => true, 'verified_at' => now()]);
    }
}

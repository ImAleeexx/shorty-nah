<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
final class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => Tag::normalise(fake()->unique()->word())];
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => Tag::normalise($name)]);
    }
}

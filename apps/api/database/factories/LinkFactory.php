<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RedirectMode;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Link>
 */
final class LinkFactory extends Factory
{
    protected $model = Link::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'domain_id' => Domain::factory(),
            'slug' => Str::random(7),
            'destination' => 'https://example.com/'.fake()->slug(),
            'redirect_mode' => null,
            'password_hash' => null,
            'expires_at' => null,
            'max_clicks' => null,
            'click_count' => 0,
            'disabled_at' => null,
            'referrer_policy' => null,
            'created_by' => User::factory(),
        ];
    }

    public function forDomain(Domain $domain): static
    {
        return $this->state(fn (): array => ['domain_id' => $domain->id]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (): array => ['created_by' => $user->id]);
    }

    public function withSlug(string $slug): static
    {
        return $this->state(fn (): array => ['slug' => $slug]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['disabled_at' => now()]);
    }

    public function passwordProtected(string $password = 'a quiet lantern drifts'): static
    {
        return $this->state(fn (): array => ['password_hash' => Hash::make($password)]);
    }

    public function limitReached(): static
    {
        return $this->state(fn (): array => ['max_clicks' => 5, 'click_count' => 5]);
    }

    public function interstitial(): static
    {
        return $this->state(fn (): array => ['redirect_mode' => RedirectMode::Interstitial->value]);
    }
}

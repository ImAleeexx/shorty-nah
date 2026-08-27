<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RuleKind;
use App\Models\Link;
use App\Models\LinkRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LinkRule>
 */
final class LinkRuleFactory extends Factory
{
    protected $model = LinkRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'link_id' => Link::factory(),
            'position' => 0,
            'kind' => RuleKind::Country,
            'value' => 'ES',
            'destination' => 'https://example.com/es',
        ];
    }

    public function forLink(Link $link): self
    {
        return $this->state(fn (): array => ['link_id' => $link->id]);
    }

    public function at(int $position): self
    {
        return $this->state(fn (): array => ['position' => $position]);
    }

    public function of(RuleKind $kind, string $value, string $destination): self
    {
        return $this->state(fn (): array => [
            'kind' => $kind,
            'value' => $value,
            'destination' => $destination,
        ]);
    }
}

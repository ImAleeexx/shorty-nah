<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuleKind;
use App\Observers\LinkRuleObserver;
use Database\Factories\LinkRuleFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One condition and where it sends a visitor who satisfies it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $link_id
 * @property int $position
 * @property RuleKind $kind
 * @property string $value
 * @property string $destination
 */
#[ObservedBy(LinkRuleObserver::class)]
final class LinkRule extends Model
{
    /** @use HasFactory<LinkRuleFactory> */
    use HasFactory, HasUlids;

    /**
     * Nothing is fillable from request input: a destination has to go through
     * the same validation a link's does, which is a service's job.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RuleKind::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Link, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}

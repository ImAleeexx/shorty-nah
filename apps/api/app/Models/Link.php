<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RedirectMode;
use App\Observers\LinkObserver;
use Database\Factories\LinkFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $domain_id
 * @property string $slug
 * @property string $destination
 * @property RedirectMode|null $redirect_mode
 * @property string|null $password_hash
 * @property Carbon|null $expires_at
 * @property int|null $max_clicks
 * @property int $click_count
 * @property Carbon|null $disabled_at
 * @property string|null $referrer_policy
 * @property int|null $created_by
 */
#[ObservedBy(LinkObserver::class)]
final class Link extends Model
{
    /** @use HasFactory<LinkFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * Nothing is fillable from request input. Slug, destination and owner are all
     * set through a service that validates and normalises them.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['password_hash'];

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
            'redirect_mode' => RedirectMode::class,
            'expires_at' => 'datetime',
            'disabled_at' => 'datetime',
            'max_clicks' => 'integer',
            'click_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasReachedClickLimit(): bool
    {
        return $this->max_clicks !== null && $this->click_count >= $this->max_clicks;
    }

    public function requiresPassword(): bool
    {
        return $this->password_hash !== null;
    }

    /**
     * Whether the link should still send a visitor anywhere. Deliberately does
     * not say why it should not: the redirect path must not disclose which
     * condition failed.
     */
    public function resolvable(): bool
    {
        return ! $this->isDisabled()
            && ! $this->isExpired()
            && ! $this->hasReachedClickLimit();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // An administrator sees every link; anyone else sees only their own. This
        // is the query-level half of the authorization rule — the controller
        // still answers 404 rather than 403 for anything it excludes.
        return $user->administrates()
            ? $query
            : $query->where('created_by', $user->id);
    }

    protected static function newFactory(): LinkFactory
    {
        return LinkFactory::new();
    }
}

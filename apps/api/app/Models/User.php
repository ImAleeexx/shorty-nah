<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property Role $role
 * @property string $password
 * @property Carbon|null $password_changed_at
 * @property Carbon|null $last_authenticated_at
 * @property Carbon|null $disabled_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    /**
     * Only these may be filled from request input. Role is absent on purpose:
     * it is assigned through an authorized path, never by a request body.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * The route key is the ULID, so no URL ever carries the integer key.
     */
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'last_authenticated_at' => 'datetime',
            'disabled_at' => 'datetime',
            'role' => Role::class,
        ];
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }

    public function administrates(): bool
    {
        return $this->role->administrates();
    }

    public function mayWrite(): bool
    {
        return $this->role->mayWrite() && ! $this->isDisabled();
    }

    /**
     * Whether the account authenticated recently enough to perform a sensitive
     * operation without proving its password again.
     */
    public function authenticatedRecently(int $withinSeconds): bool
    {
        return $this->last_authenticated_at !== null
            && $this->last_authenticated_at->greaterThan(Carbon::now()->subSeconds($withinSeconds));
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'invited_by');
    }

    public static function ownerCount(): int
    {
        return self::query()->where('role', Role::Owner->value)->count();
    }

    /**
     * Sessions are keyed to the password, so changing it invalidates every other
     * one. Rotating the remember token stops a stolen cookie surviving too.
     */
    public function markPasswordChanged(): void
    {
        $this->forceFill([
            'password_changed_at' => Carbon::now(),
            'remember_token' => Str::random(60),
        ])->save();
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();

        self::creating(function (Model $model): void {
            // Guarantees the exposed identifier exists even when a row is created
            // outside the factory.
            if ($model instanceof self && ($model->public_id ?? null) === null) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }
}

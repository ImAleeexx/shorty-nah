<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One second factor belonging to an account.
 *
 * Authenticator apps and passkeys share a table because everything that asks
 * "does this account have a second factor" means the same question for both.
 */
final class TwoFactorCredential extends Model
{
    public const TOTP = 'totp';

    public const WEBAUTHN = 'webauthn';

    use HasUlids;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

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
            'user_id' => 'integer',
            'secret' => 'encrypted',
            'sign_count' => 'integer',
            'last_timestep' => 'integer',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}

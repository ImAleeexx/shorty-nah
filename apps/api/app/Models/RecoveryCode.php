<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use way back in.
 *
 * Only the hash is stored. A recovery code is an issued secret like any other,
 * and the whole point of it is that it works when the authenticator does not —
 * which is exactly when a leaked backup would be most useful to somebody else.
 */
final class RecoveryCode extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'used_at' => 'datetime',
        ];
    }
}

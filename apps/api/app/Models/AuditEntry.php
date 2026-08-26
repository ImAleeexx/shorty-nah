<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded security-relevant event.
 *
 * Append-only, and enforced by the database rather than here: the application's
 * role holds no UPDATE or DELETE privilege on this table. A guard in code is
 * only as good as the next person who writes a query.
 */
final class AuditEntry extends Model
{
    // No factory: an entry is only ever written by AuditLog, and a factory would
    // be a second way to create history.
    public $timestamps = false;

    protected $fillable = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}

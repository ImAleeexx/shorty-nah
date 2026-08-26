<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes the audit trail.
 *
 * Recording never fails the operation it describes. An audit write that throws
 * would turn a successful sign-in into an error, which is both wrong and a
 * denial-of-service waiting to be found — so a failure here is swallowed after
 * the fact rather than propagated.
 */
final class AuditLog
{
    public function __construct(private readonly string $applicationKey) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(
        AuditAction $action,
        ?User $actor = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $context = [],
        ?Request $request = null,
    ): void {
        DB::table('audit_entries')->insert([
            'actor_id' => $actor?->id,
            // Copied rather than joined: an entry has to stay readable after the
            // account it names is deleted.
            'actor_email' => $actor?->email,
            'action' => $action->value,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'source_hash' => $this->source($request),
            'context' => $context === [] ? null : json_encode($context),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * A derived identifier for where a request came from.
     *
     * Never the address. Two entries from one source match, which is what makes
     * the log useful, but nothing here identifies anyone without the
     * application key.
     */
    private function source(?Request $request): ?string
    {
        $address = $request?->ip();

        if ($address === null || $address === '') {
            return null;
        }

        return hash_hmac('sha256', $address, $this->applicationKey);
    }

    /**
     * @return Builder<AuditEntry>
     */
    public function query(): Builder
    {
        // Newest first is the only order anyone reads an audit log in.
        return AuditEntry::query()->orderByDesc('created_at')->orderByDesc('id');
    }
}

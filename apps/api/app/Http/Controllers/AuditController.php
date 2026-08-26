<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The audit log, for owners.
 *
 * Read-only by construction rather than by omission: there is no write path
 * here, and the application's database role could not take one if there were.
 */
final class AuditController
{
    public function index(Request $request, AuditLog $audit): JsonResponse
    {
        $actor = $request->user();

        // An owner-only surface, so anyone else is not told it exists.
        if (! $actor instanceof User || ! $actor->isOwner()) {
            return new JsonResponse(status: 404);
        }

        /** @var array{actor?: string, action?: string, from?: string, to?: string, per_page?: int} $filters */
        $filters = $request->validate([
            'actor' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'in:'.implode(',', AuditAction::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $audit->query()->with('actor');

        if (isset($filters['actor']) && $filters['actor'] !== '') {
            $needle = $filters['actor'];

            $query->where(function ($inner) use ($needle): void {
                $inner->where('actor_email', 'like', "%{$needle}%")
                    ->orWhereHas('actor', fn ($actor) => $actor->where('public_id', $needle));
            });
        }

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $page = $query->paginate($filters['per_page'] ?? 50);

        return new JsonResponse([
            'entries' => collect($page->items())->map(static fn (AuditEntry $entry): array => [
                'id' => (string) $entry->getKey(),
                'action' => $entry->action,
                'actor' => [
                    'id' => $entry->actor?->public_id,
                    'email' => $entry->actor_email,
                ],
                'target' => ['type' => $entry->target_type, 'id' => $entry->target_id],
                // The derived source, never an address. Truncated because its
                // only use here is telling two sources apart.
                'source' => $entry->source_hash === null ? null : mb_substr($entry->source_hash, 0, 12),
                'context' => $entry->context,
                'recorded_at' => $entry->created_at,
            ]),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'actions' => AuditAction::values(),
        ]);
    }
}

'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import { useState } from 'react';

import { Input, Select } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';

export type AuditRecord = {
  id: string;
  action: string;
  actor: { id: string | null; email: string | null };
  target: { type: string | null; id: string | null };
  source: string | null;
  context: Record<string, unknown> | null;
  recorded_at: string;
};

/**
 * The audit log.
 *
 * Filtering goes through the server rather than the browser, unlike the link
 * list: the log grows without bound and the page holds only a window of it, so
 * narrowing in memory would narrow the wrong thing.
 */
export function AuditTable({
  entries,
  actions,
  total,
  filters,
}: {
  entries: AuditRecord[];
  actions: string[];
  total: number;
  filters: { actor: string; action: string; from: string; to: string };
}) {
  const router = useRouter();
  const params = useSearchParams();
  const [pending, setPending] = useState(false);

  function apply(next: Partial<typeof filters>) {
    const query = new URLSearchParams(params.toString());

    for (const [key, value] of Object.entries({ ...filters, ...next })) {
      if (value === '') {
        query.delete(key);
      } else {
        query.set(key, value);
      }
    }

    setPending(true);
    router.push(`/audit?${query.toString()}`);
    setPending(false);
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-ink text-xl font-semibold tracking-tight">Audit log</h1>
        <p className="text-ink-muted mt-1 text-sm">
          {total.toLocaleString()} recorded events, newest first. Entries cannot be edited or
          removed — not through this page, and not by the application at all.
        </p>
      </div>

      <div className="flex flex-wrap gap-3">
        <Input
          className="min-w-48 flex-1"
          placeholder="Filter by actor"
          aria-label="Filter by actor"
          defaultValue={filters.actor}
          onBlur={(event) => apply({ actor: event.target.value })}
          data-testid="audit-actor"
        />

        <Select
          className="w-auto"
          aria-label="Filter by action"
          value={filters.action}
          onChange={(event) => apply({ action: event.target.value })}
          data-testid="audit-action"
        >
          <option value="">All actions</option>
          {actions.map((action) => (
            <option key={action} value={action}>
              {action}
            </option>
          ))}
        </Select>

        <Input
          className="w-auto"
          type="date"
          aria-label="From"
          defaultValue={filters.from}
          onChange={(event) => apply({ from: event.target.value })}
          data-testid="audit-from"
        />

        <Input
          className="w-auto"
          type="date"
          aria-label="To"
          defaultValue={filters.to}
          onChange={(event) => apply({ to: event.target.value })}
          data-testid="audit-to"
        />

        <Button
          intent="ghost"
          size="md"
          disabled={pending}
          onClick={() => apply({ actor: '', action: '', from: '', to: '' })}
        >
          Clear
        </Button>
      </div>

      {entries.length === 0 ? (
        <EmptyState
          title="Nothing recorded here"
          description="No event matches these filters. Widen the period, or clear the action."
          action={
            <Button
              intent="outline"
              size="md"
              onClick={() => apply({ actor: '', action: '', from: '', to: '' })}
            >
              Clear filters
            </Button>
          }
        />
      ) : (
        <ul className="border-border divide-border divide-y rounded-(--radius-token) border">
          {entries.map((entry) => (
            <li
              key={entry.id}
              className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3"
              data-testid="audit-row"
              data-action={entry.action}
            >
              <span className="tabular text-ink-muted w-44 shrink-0 text-xs">
                {new Date(entry.recorded_at).toLocaleString()}
              </span>

              <span className="text-ink text-sm">{entry.action}</span>

              <span className="text-ink-muted min-w-0 flex-1 truncate text-xs">
                {entry.actor.email ?? 'system'}
                {entry.target.id === null
                  ? ''
                  : ` → ${entry.target.type ?? 'object'} ${entry.target.id}`}
              </span>

              {entry.source === null ? null : (
                <span className="tabular text-ink-subtle text-xs" title="Derived source identifier">
                  {entry.source}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

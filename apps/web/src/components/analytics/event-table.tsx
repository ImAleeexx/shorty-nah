'use client';

import { useCallback, useRef, useState } from 'react';
import { Virtuoso } from 'react-virtuoso';

import { apiRequest } from '@/lib/client-api';
import type { ClickEvent, EventPage } from '@/lib/analytics';

/**
 * The raw drill-down.
 *
 * The one view that can legitimately hold thousands of rows, so it is
 * virtualized: the API paginates and Virtuoso renders only the window, which is
 * what keeps scrolling smooth when a busy link's day is loaded.
 */
export function EventTable({
  linkId,
  initial,
  total,
  perPage,
}: {
  linkId: string;
  initial: ClickEvent[];
  total: number;
  perPage: number;
}) {
  const [events, setEvents] = useState<ClickEvent[]>(initial);
  const [loading, setLoading] = useState(false);
  const page = useRef(1);

  const loadMore = useCallback(async () => {
    if (loading || events.length >= total) {
      return;
    }

    setLoading(true);

    const next = page.current + 1;
    const result = await apiRequest<EventPage>(
      `/api/v1/links/${linkId}/events?page=${next}&per_page=${perPage}`,
    );

    setLoading(false);

    if (!result.ok) {
      return;
    }

    page.current = next;
    setEvents((previous) => [...previous, ...result.data.events]);
  }, [linkId, loading, events.length, total, perPage]);

  if (total === 0) {
    return <p className="text-ink-muted text-sm">No clicks recorded in this period.</p>;
  }

  return (
    <div className="border-border rounded-(--radius-token) border" data-testid="event-table">
      <Virtuoso
        style={{ height: 360 }}
        data={events}
        endReached={() => void loadMore()}
        itemContent={(index, event) => <EventRow index={index} event={event} />}
        components={{
          Footer: () =>
            events.length < total ? (
              <p className="text-ink-subtle px-4 py-3 text-xs">
                {loading ? 'Loading' : `${events.length} of ${total.toLocaleString()}`}
              </p>
            ) : null,
        }}
      />
    </div>
  );
}

function EventRow({ index, event }: { index: number; event: ClickEvent }) {
  const at = typeof event.occurred_at === 'string' ? new Date(event.occurred_at) : null;

  return (
    <div
      className="border-border flex items-center gap-4 border-b px-4 py-2 last:border-b-0"
      data-testid="event-row"
      data-index={index}
    >
      <span className="tabular text-ink-muted w-40 shrink-0 text-xs">
        {at === null || Number.isNaN(at.getTime()) ? '—' : at.toLocaleString()}
      </span>
      <span className="text-ink truncate text-xs">{describe(event)}</span>
    </div>
  );
}

/**
 * Raw addresses are never stored, so a row is described by what enrichment kept:
 * where it appeared to come from and what it was using.
 */
function describe(event: ClickEvent): string {
  const parts = ['country', 'browser', 'operating_system', 'device_type', 'referrer_host']
    .map((key) => event[key])
    .filter((value): value is string => typeof value === 'string' && value !== '');

  return parts.length === 0 ? 'Recorded' : parts.join(' · ');
}

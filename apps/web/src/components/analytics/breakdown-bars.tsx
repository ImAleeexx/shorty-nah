'use client';

import type { Breakdown } from '@/lib/analytics';

/**
 * Magnitude by identity.
 *
 * Horizontal, because the labels are words of varying length and rotating them
 * to fit a vertical axis costs more than the space it saves. Bars are drawn in
 * plain markup rather than a chart library: one measure, one dimension, no axis
 * — a charting runtime here would be weight without a job.
 *
 * A single series, so no legend: the caption names the measure. Values are
 * labelled directly, in ink rather than in the bar's colour.
 */
export function BreakdownBars({
  title,
  rows,
  emptyLabel,
}: {
  title: string;
  rows: Breakdown[];
  emptyLabel: string;
}) {
  const highest = rows.reduce((max, row) => Math.max(max, row.counted), 0);

  return (
    <figure className="flex flex-col gap-3" data-testid={`breakdown-${title.toLowerCase()}`}>
      <figcaption className="text-ink-subtle text-xs font-medium tracking-wide uppercase">
        {title}
      </figcaption>

      {rows.length === 0 ? (
        <p className="text-ink-muted text-sm">{emptyLabel}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {rows.slice(0, 8).map((row) => (
            <li key={row.label} className="flex flex-col gap-1">
              <div className="flex items-baseline justify-between gap-3">
                <span className="text-ink truncate text-sm">{row.label}</span>
                <span className="tabular text-ink-muted text-xs">
                  {row.counted.toLocaleString()}
                </span>
              </div>

              <div className="bg-border/60 h-1.5 w-full overflow-hidden rounded-[3px]">
                <div
                  className="bg-accent h-full rounded-[3px]"
                  // The only inline style here is the measured length, which is
                  // the datum itself and cannot live in a class.
                  style={{ width: `${highest === 0 ? 0 : (row.counted / highest) * 100}%` }}
                />
              </div>
            </li>
          ))}
        </ul>
      )}
    </figure>
  );
}

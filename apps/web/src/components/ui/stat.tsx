'use client';

import NumberFlow from '@number-flow/react';

import { cn } from '@/lib/cn';

/**
 * A single figure.
 *
 * NumberFlow animates a digit change only when `live` is set — that is, only when
 * the value changes while somebody is watching. A count-up on page load is
 * decoration applied to the number a viewer came to read, and it delays the one
 * thing the page exists to show.
 */
export function Stat({
  label,
  value,
  hint,
  live = false,
  className,
}: {
  label: string;
  value: number;
  hint?: string;
  live?: boolean;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-col gap-1', className)}>
      <span className="text-ink-subtle text-xs font-medium tracking-wide uppercase">{label}</span>

      <span className="tabular text-ink text-3xl leading-none font-semibold tracking-tight">
        {live ? <NumberFlow value={value} respectMotionPreference /> : value.toLocaleString()}
      </span>

      {hint ? <span className="text-ink-muted text-xs">{hint}</span> : null}
    </div>
  );
}

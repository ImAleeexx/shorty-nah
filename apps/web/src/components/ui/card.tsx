import type { HTMLAttributes, ReactNode } from 'react';

import { cn } from '@/lib/cn';

/**
 * A bento cell.
 *
 * Separated by elevation rather than by a line. The border is gone: a hairline
 * and a shadow doing the same job at once is what makes a card look like it has
 * an outline drawn round a photograph of itself.
 *
 * `--elevation-edge` is the other half, and it is why this reads in both modes.
 * In light it resolves to nothing — a white card on a near-white canvas has no
 * lit edge to catch. In dark it is a one-pixel inset highlight along the top,
 * because shadow separates nothing against a dark ground and the edge has to.
 */
export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        'bg-surface flex flex-col rounded-(--radius-token-lg)',
        'shadow-(--shadow-card)',
        className,
      )}
      {...props}
    />
  );
}

export function CardHeader({
  title,
  description,
  action,
}: {
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
}) {
  return (
    <div className="border-border/70 flex items-start justify-between gap-4 border-b px-6 py-4">
      <div className="min-w-0">
        <h2 className="text-ink truncate text-sm font-semibold tracking-tight">{title}</h2>
        {description ? <p className="text-ink-muted mt-1 text-xs">{description}</p> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  );
}

export function CardBody({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  // Bottom padding runs a little longer than the top. Matching them measures
  // equal and looks top-heavy, because the header rule above already reads as
  // weight on that edge.
  return <div className={cn('flex-1 px-6 pt-5 pb-6', className)} {...props} />;
}

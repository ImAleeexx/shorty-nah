import type { HTMLAttributes, ReactNode } from 'react';

import { cn } from '@/lib/cn';

/**
 * A bento cell.
 *
 * One hairline border, a clamped radius, no shadow. The nested-enclosure
 * treatment that reads as expensive on a landing page reads as noise on a page
 * someone opens every morning, and its blur costs GPU on the densest views here.
 */
export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        'border-border bg-surface flex flex-col rounded-(--radius-token) border',
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
    <div className="border-border flex items-start justify-between gap-4 border-b px-6 py-4">
      <div className="min-w-0">
        <h2 className="text-ink truncate text-sm font-semibold tracking-tight">{title}</h2>
        {description ? <p className="text-ink-muted mt-1 text-xs">{description}</p> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  );
}

export function CardBody({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  // Generous internal padding is the whole spatial idea; a cramped cell reads as
  // a table pretending to be a card.
  return <div className={cn('flex-1 px-6 py-5', className)} {...props} />;
}

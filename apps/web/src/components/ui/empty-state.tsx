import type { ReactNode } from 'react';

/**
 * What a surface says when it has nothing to show.
 *
 * Seen rarely, so this is one of the few places the design permits the editorial
 * serif and a little presence. It always states the next action: reporting absence
 * without offering a way forward leaves the viewer stuck.
 */
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
      <h3 className="text-ink font-serif text-2xl leading-tight tracking-tight">{title}</h3>
      <p className="text-ink-muted max-w-sm text-sm">{description}</p>
      {action ? <div className="mt-2">{action}</div> : null}
    </div>
  );
}

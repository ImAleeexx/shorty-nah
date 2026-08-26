import type { ReactNode } from 'react';

/**
 * The frame both credential screens share.
 *
 * Narrow and centred, with no navigation: there is nowhere else to go until
 * there is a session.
 */
export function AuthShell({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <main className="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center px-6 py-16">
      <div className="flex flex-col gap-2">
        <h1 className="text-ink text-xl font-semibold tracking-tight">{title}</h1>
        <p className="text-ink-muted text-sm">{description}</p>
      </div>

      <div className="mt-8">{children}</div>

      {footer ? <div className="text-ink-muted mt-6 text-xs">{footer}</div> : null}
    </main>
  );
}

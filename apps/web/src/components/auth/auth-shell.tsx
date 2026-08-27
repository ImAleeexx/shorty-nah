import type { ReactNode } from 'react';

import { ThemeToggle } from '@/components/theme-toggle';
import type { Branding } from '@/lib/branding';

/**
 * The frame both credential screens share.
 *
 * The form sits on an elevated card, which is not decoration: this system
 * separates a surface from its ground by elevation, and the one screen that had
 * no surface at all was the first one anybody sees. A form floating on bare
 * canvas reads as a page that has not finished loading.
 *
 * No entrance animation, deliberately. The motion contract allows real flourish
 * on exactly three surfaces — the setup wizard, the interstitial and empty
 * states — and this is not one of them. Somebody reaching this page has usually
 * just been signed out mid-task, and making them wait for a card to arrive is
 * the wrong first impression.
 */
export function AuthShell({
  branding,
  title,
  description,
  children,
  footer,
}: {
  branding: Branding;
  title: string;
  description: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <main className="relative flex min-h-dvh flex-1 flex-col items-center justify-center px-6 py-16">
      {/* Reachable before there is a session: someone signing in on a machine
          set to the mode they do not want should not have to sign in first to
          change it. */}
      <div className="absolute top-5 right-5">
        <ThemeToggle />
      </div>

      <div className="w-full max-w-100">
        {/* The instance says what it is above the card rather than inside it, so
            the card holds one thing and the identity is not competing with the
            first field for attention. */}
        <div className="mb-7 flex flex-col items-center gap-3 text-center">
          {branding.wordmark !== null ? (
            /* eslint-disable-next-line @next/next/no-img-element -- an
               operator-uploaded asset of unknown dimensions, re-encoded and
               size-bounded server-side. */
            <img src={branding.wordmark} alt={branding.name} className="max-h-8 w-auto" />
          ) : branding.logo !== null ? (
            /* eslint-disable-next-line @next/next/no-img-element -- as above. */
            <img src={branding.logo} alt={branding.name} className="max-h-10 w-auto" />
          ) : (
            <span className="text-ink text-sm font-semibold tracking-tight">{branding.name}</span>
          )}
        </div>

        <div className="bg-surface rounded-(--radius-token-lg) px-7 pt-7 pb-8 shadow-(--shadow-card)">
          <div className="flex flex-col gap-1.5">
            <h1 className="text-ink text-lg font-semibold tracking-tight text-balance">{title}</h1>
            <p className="text-ink-muted text-sm text-pretty">{description}</p>
          </div>

          <div className="mt-7">{children}</div>
        </div>

        {footer ? (
          <p className="text-ink-muted mt-5 text-center text-xs text-pretty">{footer}</p>
        ) : null}
      </div>
    </main>
  );
}

import type { ReactNode } from 'react';

import { AppNav } from '@/components/app-nav';
import { CommandPalette } from '@/components/command-palette';
import { SignOutButton } from '@/components/auth/sign-out-button';
import { ThemeToggle } from '@/components/theme-toggle';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { Branding } from '@/lib/branding';
import type { LinkRecord } from '@/lib/links';

/**
 * The frame every signed-in page renders inside.
 *
 * Deliberately stable: the same header in the same place every time, because
 * muscle memory is worth more here than variety. Nothing in this shell animates
 * on navigation — an operator moves between these pages dozens of times a day.
 */
export function AppShell({
  branding,
  children,
  links = [],
  owner = false,
}: {
  branding: Branding;
  children: ReactNode;
  links?: LinkRecord[];
  owner?: boolean;
}) {
  return (
    <TooltipProvider>
      <div className="flex min-h-full flex-col">
        {/* Hidden until focused. The header carries navigation, a theme control
            and sign-out before the page's own content, and a keyboard user
            should not have to walk past all of it on every navigation. */}
        <a
          href="#content"
          className="bg-surface text-ink border-border sr-only rounded-(--radius-token-sm) border px-3 py-2 text-sm focus-visible:not-sr-only focus-visible:absolute focus-visible:top-3 focus-visible:left-3 focus-visible:z-50"
        >
          Skip to content
        </a>

        <header className="border-border bg-surface border-b">
          <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 py-3 md:px-8">
            <div className="flex min-w-0 items-center gap-3">
              {branding.logo === null ? (
                <span className="text-ink truncate text-sm font-semibold tracking-tight">
                  {branding.name}
                </span>
              ) : (
                /* eslint-disable-next-line @next/next/no-img-element -- an
                   operator-uploaded asset of unknown dimensions, already
                   re-encoded and size-bounded server-side. */
                <img src={branding.logo} alt={branding.name} className="max-h-6 w-auto" />
              )}
            </div>

            <div className="flex items-center gap-1">
              <AppNav owner={owner} />
              <ThemeToggle />
              <SignOutButton />
            </div>
          </div>
        </header>

        <main
          id="content"
          tabIndex={-1}
          className="mx-auto w-full max-w-7xl flex-1 px-4 py-8 md:px-8 md:py-10"
        >
          {children}
        </main>

        <CommandPalette links={links} />
      </div>
    </TooltipProvider>
  );
}

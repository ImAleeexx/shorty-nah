'use client';

import { useEffect } from 'react';

import { Button } from '@/components/ui/button';
import { ButtonLink } from '@/components/ui/button-link';

/**
 * What renders when a page throws.
 *
 * The message is never shown. A rendering error can carry a stack, a query or a
 * configuration value, and this boundary is reachable by anyone who can reach
 * the page — so it says what happened and offers a way forward, and the detail
 * stays in the server log where an operator can read it deliberately.
 */
export default function ErrorBoundary({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    // The digest correlates this screen with the server-side log entry that
    // holds the actual cause.
    console.error('A page failed to render.', error.digest);
  }, [error]);

  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center">
      <h1 className="text-ink font-serif text-3xl leading-tight tracking-tight text-balance">
        This page could not be loaded
      </h1>

      <p className="text-ink-muted max-w-sm text-sm text-pretty">
        The instance is running, but something went wrong rendering this screen. Trying again often
        works; if it does not, the server log carries the detail.
      </p>

      {error.digest === undefined ? null : (
        <p className="text-ink-subtle tabular text-xs">Reference {error.digest}</p>
      )}

      <div className="mt-2 flex items-center gap-3">
        <Button intent="primary" size="md" onClick={reset}>
          Try again
        </Button>

        <ButtonLink href="/" intent="ghost" size="md">
          Back to the overview
        </ButtonLink>
      </div>
    </main>
  );
}

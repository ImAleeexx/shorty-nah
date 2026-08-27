import type { Metadata } from 'next';

import { ButtonLink } from '@/components/ui/button-link';

export const metadata: Metadata = { title: 'Not found' };

/**
 * The page for a URL that resolves to nothing.
 *
 * Deliberately outside the app shell. A 404 is reachable while signed out, and
 * rendering the navigation would either need a session it may not have or show
 * an operator's own instance to a stranger who guessed a path.
 *
 * It also says nothing about what does exist. This host serves short links, so
 * a helpful "did you mean" here would turn the error page into a way of probing
 * which slugs are real.
 */
export default function NotFound() {
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center">
      <p className="text-ink-subtle tabular text-xs font-medium tracking-wide uppercase">404</p>

      <h1 className="text-ink font-serif text-3xl leading-tight tracking-tight text-balance">
        There is nothing at this address
      </h1>

      <p className="text-ink-muted max-w-sm text-sm text-pretty">
        The page may have been removed, or the address may have been mistyped.
      </p>

      <div className="mt-2">
        <ButtonLink href="/" intent="outline" size="md">
          Back to the overview
        </ButtonLink>
      </div>
    </main>
  );
}

'use client';

import { useTheme } from 'next-themes';
import { Toaster as Sonner } from 'sonner';

/**
 * Action feedback.
 *
 * The colour mode is read from next-themes rather than left on Sonner's own
 * default, because "system" here means the attribute the token layer keys on,
 * not what Sonner would infer independently.
 *
 * Styling comes from the token layer through CSS variables: Sonner's classes are
 * its own, and reaching into them with utilities breaks on every upgrade.
 */
export function Toaster() {
  const { resolvedTheme } = useTheme();

  return (
    <Sonner
      theme={resolvedTheme === 'dark' ? 'dark' : 'light'}
      position="bottom-right"
      closeButton
      toastOptions={{
        style: {
          background: 'var(--surface)',
          border: '1px solid var(--border)',
          borderRadius: 'var(--radius-token)',
          color: 'var(--ink)',
        },
      }}
    />
  );
}

'use client';

import { ThemeProvider as NextThemeProvider } from 'next-themes';
import type { ReactNode } from 'react';

/**
 * Colour-mode switching without a flash.
 *
 * next-themes writes the attribute from a blocking inline script before first
 * paint. Without it the document renders in one mode and corrects itself, which
 * is visible and unpleasant on every load.
 *
 * `data-theme` rather than a class, because the token layer keys its overrides on
 * that attribute and on prefers-color-scheme — the two halves of the same rule.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  return (
    <NextThemeProvider
      attribute="data-theme"
      defaultTheme="system"
      enableSystem
      disableTransitionOnChange
    >
      {children}
    </NextThemeProvider>
  );
}

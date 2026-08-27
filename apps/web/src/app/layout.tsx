import type { Metadata } from 'next';
import type { ReactNode } from 'react';

import { InstanceFooter } from '@/components/instance-footer';
import { ThemeProvider } from '@/components/theme-provider';
import { Toaster } from '@/components/ui/toaster';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding, typefaceStack } from '@/lib/branding';
import { fontVariables } from '@/lib/fonts';

import './globals.css';

export async function generateMetadata(): Promise<Metadata> {
  const branding = sanitiseBranding(await fetchPublicConfiguration());

  return {
    title: { default: branding.name, template: `%s · ${branding.name}` },
    description: `${branding.name} link management`,
    icons: branding.favicon === null ? undefined : { icon: branding.favicon },
    robots: { index: false, follow: false },
  };
}

export default async function RootLayout({ children }: { children: ReactNode }) {
  const branding = sanitiseBranding(await fetchPublicConfiguration());

  return (
    <html
      lang="en"
      suppressHydrationWarning
      className={`${fontVariables} h-full`}
      // Inlined on the document itself, so the operator's accent is present on
      // first paint rather than arriving after it.
      style={{
        ['--accent' as string]: branding.accent,
        ['--radius' as string]: `${branding.radius}px`,
        // The chosen face has to reach the document, not just the settings
        // table. Inlined with the rest so it is right on first paint.
        ['--font-sans' as string]: typefaceStack(branding.typeface),
      }}
    >
      <body className="flex min-h-full flex-col">
        <ThemeProvider>
          {children}
          <InstanceFooter text={branding.footer} />
          <Toaster />
        </ThemeProvider>
      </body>
    </html>
  );
}

import type { Metadata } from 'next';
import Link from 'next/link';
import { redirect } from 'next/navigation';

import { AuthShell } from '@/components/auth/auth-shell';
import { SignInForm } from '@/components/auth/sign-in-form';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import { currentViewer } from '@/lib/session';

export const metadata: Metadata = { title: 'Sign in' };

export const dynamic = 'force-dynamic';

export default async function SignInPage() {
  const configuration = await fetchPublicConfiguration();

  if (configuration !== null && !configuration.installed) {
    redirect('/setup');
  }

  if ((await currentViewer()) !== null) {
    redirect('/');
  }

  const branding = sanitiseBranding(configuration);
  const mode = configuration?.registration.mode ?? 'closed';

  return (
    <AuthShell
      title={`Sign in to ${branding.name}`}
      description="This instance is private."
      footer={
        // Registration is not merely hidden when closed: the route itself is
        // gone, and a link to it would be a dead end.
        mode === 'closed' ? null : (
          <>
            {mode === 'invite' ? 'Holding an invitation? ' : 'No account? '}
            <Link href="/register" className="text-ink underline underline-offset-2">
              Create one
            </Link>
            .
          </>
        )
      }
    >
      <SignInForm />
    </AuthShell>
  );
}

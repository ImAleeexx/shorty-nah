import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound, redirect } from 'next/navigation';

import { AuthShell } from '@/components/auth/auth-shell';
import { RegisterForm } from '@/components/auth/register-form';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import { currentViewer } from '@/lib/session';

export const metadata: Metadata = { title: 'Create an account' };

export const dynamic = 'force-dynamic';

export default async function RegisterPage() {
  const configuration = await fetchPublicConfiguration();

  if (configuration !== null && !configuration.installed) {
    redirect('/setup');
  }

  if ((await currentViewer()) !== null) {
    redirect('/');
  }

  const mode = configuration?.registration.mode ?? 'closed';

  // A closed instance does not have a registration screen to refuse; it has no
  // registration screen.
  if (mode === 'closed') {
    notFound();
  }

  const branding = sanitiseBranding(configuration);

  return (
    <AuthShell
      title={`Join ${branding.name}`}
      description={
        mode === 'invite'
          ? 'An invitation code is required on this instance.'
          : 'Anyone with the address may create an account on this instance.'
      }
      footer={
        <>
          Already have an account?{' '}
          <Link href="/sign-in" className="text-ink underline underline-offset-2">
            Sign in
          </Link>
          .
        </>
      }
    >
      <RegisterForm requiresInvitation={mode === 'invite'} />
    </AuthShell>
  );
}

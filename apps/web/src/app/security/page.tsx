import type { Metadata } from 'next';
import { redirect } from 'next/navigation';

import { AppShell } from '@/components/app-shell';
import { FactorManager, type Credential } from '@/components/security/factor-manager';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import { apiGet } from '@/lib/server-api';
import { currentViewer, owns } from '@/lib/session';

export const metadata: Metadata = { title: 'Security' };

export const dynamic = 'force-dynamic';

type Status = {
  required: boolean;
  enrolled: boolean;
  recovery_codes_remaining: number;
  credentials: Credential[];
};

/**
 * The one signed-in surface that must stay reachable without a second factor.
 *
 * Every other page redirects an unenrolled account here while the instance
 * requires one; this page does not, or the requirement becomes a redirect loop
 * on top of a lockout.
 */
export default async function SecurityPage() {
  const configuration = await fetchPublicConfiguration();

  if (configuration !== null && !configuration.installed) {
    redirect('/setup');
  }

  const viewer = await currentViewer();

  if (viewer === null) {
    redirect('/sign-in');
  }

  const status = await apiGet<Status>('/api/v1/auth/two-factor');

  const value: Status = status.ok
    ? status.data
    : { required: false, enrolled: false, recovery_codes_remaining: 0, credentials: [] };

  return (
    <AppShell branding={sanitiseBranding(configuration)} owner={owns(viewer)}>
      <div className="mb-8">
        <h1 className="text-ink text-xl font-semibold tracking-tight">Security</h1>
        <p className="text-ink-muted mt-1 text-sm text-pretty">
          Second factors on your own account.
        </p>
      </div>

      <div className="max-w-2xl">
        <Card>
          <CardHeader
            title="Two-factor authentication"
            description="An authenticator app, plus recovery codes issued once when the first factor is added."
          />
          <CardBody>
            <FactorManager
              credentials={value.credentials}
              required={value.required}
              enrolled={value.enrolled}
              recoveryRemaining={value.recovery_codes_remaining}
            />
          </CardBody>
        </Card>
      </div>
    </AppShell>
  );
}

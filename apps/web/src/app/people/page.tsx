import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';

import { AppShell } from '@/components/app-shell';
import { PeopleManager, type Invitation, type Person } from '@/components/people/people-manager';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import { apiGet } from '@/lib/server-api';
import { administrates, currentViewer, owns } from '@/lib/session';

export const metadata: Metadata = { title: 'People' };

export const dynamic = 'force-dynamic';

export default async function PeoplePage() {
  const configuration = await fetchPublicConfiguration();

  if (configuration !== null && !configuration.installed) {
    redirect('/setup');
  }

  const viewer = await currentViewer();

  if (viewer === null) {
    redirect('/sign-in');
  }

  // A member or viewer is not told this page exists.
  if (!administrates(viewer)) {
    notFound();
  }

  const [users, invitations] = await Promise.all([
    apiGet<{ users: Person[] }>('/api/v1/users'),
    apiGet<{ invitations: Invitation[] }>('/api/v1/invitations'),
  ]);

  return (
    <AppShell branding={sanitiseBranding(configuration)} owner={owns(viewer)}>
      <PeopleManager
        viewer={viewer}
        people={users.ok ? users.data.users : []}
        invitations={invitations.ok ? invitations.data.invitations : []}
      />
    </AppShell>
  );
}

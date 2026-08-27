import type { Metadata } from 'next';
import { redirect } from 'next/navigation';

import { AppShell } from '@/components/app-shell';
import { LinkTable } from '@/components/links/link-table';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import type { DomainRecord, LinkPage } from '@/lib/links';
import { apiGet } from '@/lib/server-api';
import { currentViewer, mayWrite, owns, mustEnrolSecondFactor } from '@/lib/session';

export const metadata: Metadata = { title: 'Links' };

export const dynamic = 'force-dynamic';

export default async function LinksPage() {
  const configuration = await fetchPublicConfiguration();

  if (configuration !== null && !configuration.installed) {
    redirect('/setup');
  }

  const viewer = await currentViewer();

  if (viewer === null) {
    redirect('/sign-in');
  }

  // An account the instance confines to enrolment is sent there rather than
  // shown a refusal it cannot act on. Every route past the requirement answers
  // 403, so without this the page renders empty and says nothing useful.
  if (mustEnrolSecondFactor(viewer)) {
    redirect('/security');
  }

  const [links, domains] = await Promise.all([
    apiGet<LinkPage>('/api/v1/links?per_page=100'),
    apiGet<{ domains: DomainRecord[] }>('/api/v1/domains'),
  ]);

  return (
    <AppShell
      branding={sanitiseBranding(configuration)}
      links={links.ok ? links.data.links : []}
      owner={owns(viewer)}
    >
      <LinkTable
        initial={links.ok ? links.data.links : []}
        domains={domains.ok ? domains.data.domains : []}
        canWrite={mayWrite(viewer)}
      />
    </AppShell>
  );
}

import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';

import { AppShell } from '@/components/app-shell';
import { AuditTable, type AuditRecord } from '@/components/audit/audit-table';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import { apiGet } from '@/lib/server-api';
import { currentViewer, owns, mustEnrolSecondFactor } from '@/lib/session';

export const metadata: Metadata = { title: 'Audit log' };

export const dynamic = 'force-dynamic';

type Search = { actor?: string; action?: string; from?: string; to?: string };

export default async function AuditPage({ searchParams }: { searchParams: Promise<Search> }) {
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

  // Owner-only, and anyone else is not told the page exists.
  if (!owns(viewer)) {
    notFound();
  }

  const search = await searchParams;
  const filters = {
    actor: search.actor ?? '',
    action: search.action ?? '',
    from: search.from ?? '',
    to: search.to ?? '',
  };

  const query = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value !== '') {
      query.set(key, value);
    }
  }

  const result = await apiGet<{
    entries: AuditRecord[];
    meta: { total: number };
    actions: string[];
  }>(`/api/v1/audit?${query.toString()}`);

  return (
    <AppShell branding={sanitiseBranding(configuration)} owner={owns(viewer)}>
      <AuditTable
        entries={result.ok ? result.data.entries : []}
        actions={result.ok ? result.data.actions : []}
        total={result.ok ? result.data.meta.total : 0}
        filters={filters}
      />
    </AppShell>
  );
}

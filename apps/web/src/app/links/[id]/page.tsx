import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound, redirect } from 'next/navigation';

import { AppShell } from '@/components/app-shell';
import { BreakdownBars } from '@/components/analytics/breakdown-bars';
import { ClickChart } from '@/components/analytics/click-chart';
import { EventTable } from '@/components/analytics/event-table';
import { ExportButton } from '@/components/analytics/export-button';
import { BentoCell, BentoGrid } from '@/components/ui/bento';
import { EmptyState } from '@/components/ui/empty-state';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { Stat } from '@/components/ui/stat';
import type { EventPage, Report } from '@/lib/analytics';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import { apiGet } from '@/lib/server-api';
import { currentViewer, owns, mustEnrolSecondFactor } from '@/lib/session';

export const metadata: Metadata = { title: 'Link' };

export const dynamic = 'force-dynamic';

const EVENTS_PER_PAGE = 50;

export default async function LinkReportPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

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

  const [report, events] = await Promise.all([
    apiGet<Report>(`/api/v1/links/${id}/report`),
    apiGet<EventPage>(`/api/v1/links/${id}/events?per_page=${EVENTS_PER_PAGE}`),
  ]);

  // An unauthorized read and an unknown link are the same answer on purpose.
  if (!report.ok) {
    notFound();
  }

  const { link, period, totals, series, countries, referrers, clients } = report.data;

  return (
    <AppShell branding={sanitiseBranding(configuration)} owner={owns(viewer)}>
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <Link href="/links" className="text-ink-muted hover:text-ink text-xs">
            ← Links
          </Link>
          <h1 className="tabular text-ink mt-2 text-xl font-semibold tracking-tight">
            /{link.slug}
          </h1>
          <p className="text-ink-muted mt-1 text-sm">
            {new Date(period.from).toLocaleDateString()} to{' '}
            {new Date(period.to).toLocaleDateString()} · {period.timezone}
          </p>
        </div>

        <ExportButton linkId={link.id} />
      </div>

      {totals.clicks === 0 ? (
        <Card>
          <CardBody>
            <EmptyState
              title="Nothing has arrived yet"
              description="Share the short link and the first click will appear here within a second or two. Bots and prefetches are excluded before anything is counted."
              action={
                <Link href="/links" className="text-ink text-sm underline underline-offset-2">
                  Copy the link
                </Link>
              }
            />
          </CardBody>
        </Card>
      ) : (
        <BentoGrid>
          <BentoCell span="quarter">
            <Card className="h-full">
              <CardBody>
                <Stat label="Counted" value={totals.counted} hint="Clicks by a person" />
              </CardBody>
            </Card>
          </BentoCell>

          <BentoCell span="quarter">
            <Card className="h-full">
              <CardBody>
                {/* Uniques are a merge over the whole range, so this is deliberately
                  not the sum of the series and is not presented as one. */}
                <Stat label="Visitors" value={totals.visitors} hint="Unique over the period" />
              </CardBody>
            </Card>
          </BentoCell>

          <BentoCell span="quarter">
            <Card className="h-full">
              <CardBody>
                <Stat label="Filtered" value={totals.automated} hint="Bots and prefetches" />
              </CardBody>
            </Card>
          </BentoCell>

          <BentoCell span="quarter">
            <Card className="h-full">
              <CardBody>
                <Stat label="Duplicates" value={totals.duplicates} hint="Absorbed double-fires" />
              </CardBody>
            </Card>
          </BentoCell>

          <BentoCell span="half">
            <Card className="h-full">
              <CardBody>
                <ClickChart
                  title="Clicks"
                  series={series}
                  granularity={period.granularity}
                  measure="counted"
                />
              </CardBody>
            </Card>
          </BentoCell>

          <BentoCell span="half">
            <Card className="h-full">
              <CardBody>
                <ClickChart
                  title="Visitors"
                  series={series}
                  granularity={period.granularity}
                  measure="visitors"
                />
              </CardBody>
            </Card>
          </BentoCell>

          <BentoCell span="third">
            <Card className="h-full">
              <CardBody>
                <BreakdownBars
                  title="Countries"
                  rows={countries.map((row) => ({ label: row.country, counted: row.counted }))}
                  emptyLabel="No geography resolved. A MaxMind licence key enables it."
                />
              </CardBody>
            </Card>
          </BentoCell>

          <BentoCell span="third">
            <Card className="h-full">
              <CardBody>
                <BreakdownBars
                  title="Referrers"
                  rows={referrers.map((row) => ({ label: row.referrer, counted: row.counted }))}
                  emptyLabel="Nothing arrived with a referrer."
                />
              </CardBody>
            </Card>
          </BentoCell>

          <BentoCell span="third">
            <Card className="h-full">
              <CardBody>
                <BreakdownBars
                  title="Browsers"
                  rows={clients.browsers}
                  emptyLabel="No client details recorded yet."
                />
              </CardBody>
            </Card>
          </BentoCell>
        </BentoGrid>
      )}

      <Card className="mt-6">
        <CardHeader
          title="Raw clicks"
          description="Every recorded event. Addresses are never stored, so a row shows what enrichment kept."
        />
        <CardBody className="p-0">
          <EventTable
            linkId={link.id}
            initial={events.ok ? events.data.events : []}
            total={events.ok ? events.data.meta.total : 0}
            perPage={EVENTS_PER_PAGE}
          />
        </CardBody>
      </Card>
    </AppShell>
  );
}

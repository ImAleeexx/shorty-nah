import { redirect } from 'next/navigation';

import { AppShell } from '@/components/app-shell';
import { LinkIcon } from '@/components/icons';
import { CountryBars } from '@/components/overview/country-bars';
import { Sparkline } from '@/components/overview/sparkline';
import { BentoCell, BentoGrid } from '@/components/ui/bento';

import { NewLinkButton } from '@/components/links/new-link-button';
import { ButtonLink } from '@/components/ui/button-link';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Stat } from '@/components/ui/stat';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import { apiGet } from '@/lib/server-api';
import { currentViewer, owns, mustEnrolSecondFactor } from '@/lib/session';

type Overview = {
  days: number;
  links_total: number;
  totals: { clicks: number; counted: number; visitors: number; scans: number };
  daily: { day: string; counted: number }[];
  countries: { country_code: string; counted: number }[];
  recent_links: {
    id: string;
    slug: string;
    destination: string;
    domain: string | null;
    clicks: number;
  }[];
};

const FALLBACK: Overview = {
  days: 30,
  links_total: 0,
  totals: { clicks: 0, counted: 0, visitors: 0, scans: 0 },
  daily: [],
  countries: [],
  recent_links: [],
};

export const dynamic = 'force-dynamic';

export default async function DashboardPage() {
  const configuration = await fetchPublicConfiguration();

  // An uninstalled instance has exactly one reachable destination.
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

  const branding = sanitiseBranding(configuration);

  // Every figure below is measured. This screen used to render literal zeroes
  // and an empty state regardless of what the instance held, which made the
  // first page an operator sees the least truthful one in the product.
  const response = await apiGet<{ overview: Overview }>('/api/v1/overview');
  const overview = response.ok ? response.data.overview : FALLBACK;

  const empty = overview.links_total === 0;

  return (
    <AppShell branding={branding} owner={owns(viewer)}>
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-ink text-xl font-semibold tracking-tight text-balance">Overview</h1>
          <p className="text-ink-muted mt-1 text-sm text-pretty">
            Links, clicks and where they came from.
          </p>
        </div>

        <NewLinkButton />
      </div>

      <BentoGrid data-testid="bento-grid">
        {/*
         * Every cell in a row is the same height, and the stat content is
         * centred inside it rather than pinned to the top.
         *
         * These were briefly sized to their own content, on the reasoning that
         * an elevated card holding nothing looks unfinished. That traded one
         * problem for a worse one: three cards at three different heights in a
         * single row have no shared baseline, and a row without a baseline
         * reads as broken rather than as airy. Matching the height and
         * centring the figure fixes both — the card is full, because the
         * number sits where the eye already is.
         */}
        <BentoCell span="quarter" data-testid="bento-cell">
          <Card className="h-full">
            <CardBody className="flex flex-col justify-center">
              <Stat
                label="Links"
                value={overview.links_total}
                hint={empty ? 'None created yet' : undefined}
              />
            </CardBody>
          </Card>
        </BentoCell>

        <BentoCell span="quarter" data-testid="bento-cell">
          <Card className="h-full">
            <CardBody className="flex flex-col justify-center">
              <Stat
                label="Clicks"
                value={overview.totals.counted}
                hint={`${overview.totals.visitors.toLocaleString()} unique · last ${overview.days} days`}
              />
            </CardBody>
          </Card>
        </BentoCell>

        <BentoCell span="half" data-testid="bento-cell">
          <Card className="h-full">
            <CardHeader
              title="Recent activity"
              description={`Counted clicks a day, last ${overview.days} days`}
            />
            <CardBody className="flex flex-col justify-end gap-3">
              {overview.daily.length === 0 ? (
                <p className="text-ink-muted text-sm">Nothing recorded yet.</p>
              ) : (
                <>
                  <Sparkline days={overview.daily} />

                  {/* Stated as text as well as drawn: the shape shows the trend,
                      the numbers are what somebody actually came to read. */}
                  {overview.totals.scans > 0 ? (
                    <p className="text-ink-muted text-xs">
                      <span className="tabular">{overview.totals.scans.toLocaleString()}</span> of
                      these arrived through a code
                    </p>
                  ) : null}
                </>
              )}
            </CardBody>
          </Card>
        </BentoCell>

        <BentoCell span="twoThirds" data-testid="bento-cell">
          <Card className="h-full" data-testid="links-card">
            <CardHeader
              title="Links"
              action={
                <ButtonLink href="/links" intent="ghost" size="sm">
                  <LinkIcon size={14} />
                  Manage
                </ButtonLink>
              }
            />
            <CardBody className={empty ? 'p-0' : undefined}>
              {empty ? (
                <EmptyState
                  title="No links yet"
                  description="Create your first short link and its clicks will start appearing here."
                  action={
                    <NewLinkButton label="Create a link" intent="accent" size="sm" iconSize={14} />
                  }
                />
              ) : (
                <ul className="divide-border divide-y">
                  {overview.recent_links.map((link) => (
                    <li
                      key={link.id}
                      className="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
                      data-testid="overview-link"
                    >
                      <div className="min-w-0">
                        <p className="tabular text-ink truncate text-sm">
                          {link.domain === null ? '' : `${link.domain}/`}
                          {link.slug}
                        </p>
                        <p className="text-ink-muted truncate text-xs">{link.destination}</p>
                      </div>

                      <span className="tabular text-ink-muted shrink-0 text-xs">
                        {link.clicks.toLocaleString()}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </BentoCell>

        <BentoCell span="third" data-testid="bento-cell">
          <Card className="h-full">
            <CardHeader title="Top countries" />
            {/* Centred when there is nothing to show, for the same reason the
                figures above are: a short message pinned to the top of a tall
                card leaves the card looking like it lost its contents. */}
            <CardBody
              className={
                overview.countries.length === 0 ? 'flex flex-col justify-center' : undefined
              }
            >
              {overview.countries.length === 0 ? (
                <p className="text-ink-muted text-sm">Nothing recorded yet.</p>
              ) : (
                <CountryBars countries={overview.countries} />
              )}
            </CardBody>
          </Card>
        </BentoCell>
      </BentoGrid>
    </AppShell>
  );
}

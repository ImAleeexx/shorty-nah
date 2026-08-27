import { redirect } from 'next/navigation';

import { AppShell } from '@/components/app-shell';
import { LinkIcon } from '@/components/icons';
import { BentoCell, BentoGrid } from '@/components/ui/bento';

import { NewLinkButton } from '@/components/links/new-link-button';
import { ButtonLink } from '@/components/ui/button-link';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Stat } from '@/components/ui/stat';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import { currentViewer, owns } from '@/lib/session';

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

  const branding = sanitiseBranding(configuration);

  return (
    <AppShell branding={branding} owner={owns(viewer)}>
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-ink text-xl font-semibold tracking-tight">Overview</h1>
          <p className="text-ink-muted mt-1 text-sm">Links, clicks and where they came from.</p>
        </div>

        <NewLinkButton />
      </div>

      <BentoGrid data-testid="bento-grid">
        <BentoCell span="quarter" data-testid="bento-cell">
          <Card className="h-full">
            <CardBody>
              <Stat label="Links" value={0} hint="None created yet" />
            </CardBody>
          </Card>
        </BentoCell>

        <BentoCell span="quarter" data-testid="bento-cell">
          <Card className="h-full">
            <CardBody>
              <Stat label="Clicks" value={0} hint="Last 30 days" />
            </CardBody>
          </Card>
        </BentoCell>

        <BentoCell span="half" data-testid="bento-cell">
          <Card className="h-full">
            <CardHeader title="Recent activity" description="Clicks as they arrive" />
            <CardBody className="flex items-center justify-center">
              <p className="text-ink-muted text-sm">Nothing recorded yet.</p>
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
            <CardBody className="p-0">
              <EmptyState
                title="No links yet"
                description="Create your first short link and its clicks will start appearing here."
                action={
                  <NewLinkButton label="Create a link" intent="accent" size="sm" iconSize={14} />
                }
              />
            </CardBody>
          </Card>
        </BentoCell>

        <BentoCell span="third" data-testid="bento-cell">
          <Card className="h-full">
            <CardHeader title="Top countries" />
            <CardBody className="flex items-center justify-center">
              <p className="text-ink-muted text-sm">No data yet.</p>
            </CardBody>
          </Card>
        </BentoCell>
      </BentoGrid>
    </AppShell>
  );
}

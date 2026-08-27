import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';

import { AppShell } from '@/components/app-shell';
import { BrandingEditor } from '@/components/settings/branding-editor';
import { SettingsForm, type SettingsValues } from '@/components/settings/settings-form';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';
import type { DomainRecord } from '@/lib/links';
import { apiGet } from '@/lib/server-api';
import { administrates, currentViewer, owns } from '@/lib/session';

export const metadata: Metadata = { title: 'Settings' };

export const dynamic = 'force-dynamic';

export default async function SettingsPage() {
  const configuration = await fetchPublicConfiguration();

  if (configuration !== null && !configuration.installed) {
    redirect('/setup');
  }

  const viewer = await currentViewer();

  if (viewer === null) {
    redirect('/sign-in');
  }

  // An account that cannot administrate is not told this page exists.
  if (!administrates(viewer)) {
    notFound();
  }

  const [settings, domains] = await Promise.all([
    apiGet<{ settings: SettingsValues; geo: { databases_present: boolean } }>('/api/v1/settings'),
    apiGet<{ domains: DomainRecord[] }>('/api/v1/domains'),
  ]);

  const values = settings.ok ? settings.data.settings : {};
  const geoActive = settings.ok && settings.data.geo.databases_present;
  const branding = sanitiseBranding(configuration);

  return (
    <AppShell branding={branding} owner={owns(viewer)}>
      <div className="mb-8">
        <h1 className="text-ink text-xl font-semibold tracking-tight">Settings</h1>
        <p className="text-ink-muted mt-1 text-sm">
          Every change here takes effect on the next request. Nothing restarts.
        </p>
      </div>

      <div className="flex flex-col gap-6">
        <Card>
          <CardHeader title="Branding" description="Applied at runtime, in both colour modes." />
          <CardBody>
            <BrandingEditor
              initial={{
                name: branding.name,
                accent: branding.accent,
                radius: branding.radius,
                typeface: branding.typeface,
                footer: branding.footer,
              }}
            />
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Analytics" description="What is counted, and how long it is kept." />
          <CardBody>
            <SettingsForm
              title="Analytics"
              testId="settings-analytics"
              values={values}
              fields={[
                {
                  key: 'analytics.retention_days',
                  label: 'Retention (days)',
                  hint: 'Raw events only. Rollups carry no expiry, so old reports keep their totals.',
                  kind: 'number',
                },
                {
                  key: 'analytics.bot_filtering',
                  label: 'Exclude bots and prefetches',
                  hint: 'Counts what a person did, not what a crawler fetched.',
                  kind: 'boolean',
                },
                { key: 'analytics.timezone', label: 'Reporting timezone', kind: 'text' },
                {
                  key: 'geo.maxmind_account_id',
                  label: 'MaxMind account ID',
                  hint: geoActive
                    ? 'Geographic databases are present and resolving.'
                    : 'No geographic databases. The updater reads MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY from the environment, not from here — it runs before this instance exists.',
                  kind: 'text',
                },
                { key: 'geo.maxmind_license_key', label: 'MaxMind licence key', kind: 'password' },
              ]}
            />
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Access" description="Who may create an account." />
          <CardBody>
            <SettingsForm
              title="Access"
              testId="settings-access"
              values={values}
              fields={[
                {
                  key: 'registration.mode',
                  label: 'Registration',
                  kind: 'choice',
                  choices: [
                    { value: 'closed', label: 'Closed' },
                    { value: 'invite', label: 'Invitation only' },
                    { value: 'open', label: 'Open' },
                  ],
                },
                {
                  key: 'security.two_factor_required',
                  label: 'Require a second factor',
                  kind: 'boolean',
                },
              ]}
            />
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Redirects"
            description="The instance default. Any link may override it."
          />
          <CardBody>
            <SettingsForm
              title="Redirects"
              testId="settings-redirects"
              values={values}
              fields={[
                {
                  key: 'redirect.default_mode',
                  label: 'Default mode',
                  kind: 'choice',
                  choices: [
                    { value: 'direct', label: 'Direct' },
                    { value: 'interstitial', label: 'Interstitial' },
                    { value: 'invisible', label: 'Invisible' },
                  ],
                },
                {
                  key: 'redirect.interstitial_delay_ms',
                  label: 'Interstitial hold (ms)',
                  kind: 'number',
                },
              ]}
            />
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Outbound mail"
            description="Needed for invitations and password resets."
          />
          <CardBody>
            <SettingsForm
              title="Mail"
              testId="settings-mail"
              values={values}
              fields={[
                { key: 'mail.host', label: 'SMTP host', kind: 'text' },
                { key: 'mail.port', label: 'Port', kind: 'number' },
                { key: 'mail.username', label: 'Username', kind: 'text' },
                { key: 'mail.password', label: 'Password', kind: 'password' },
                { key: 'mail.from_address', label: 'From address', kind: 'email' },
              ]}
            />
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Domains"
            description="Short domains this instance serves. A domain must verify before it resolves."
          />
          <CardBody>
            {domains.ok && domains.data.domains.length > 0 ? (
              <ul className="border-border divide-border divide-y rounded-(--radius-token) border">
                {domains.data.domains.map((domain) => (
                  <li
                    key={domain.id}
                    className="flex items-center justify-between gap-4 px-4 py-3"
                    data-testid="domain-row"
                  >
                    <span className="text-ink text-sm">{domain.host}</span>
                    <span className="flex items-center gap-3 text-xs">
                      {domain.primary ? <span className="text-ink-muted">primary</span> : null}
                      <span className={domain.verified ? 'text-ink-muted' : 'text-critical'}>
                        {domain.verified ? 'verified' : 'unverified'}
                      </span>
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-ink-muted text-sm">No domains registered.</p>
            )}
          </CardBody>
        </Card>
      </div>
    </AppShell>
  );
}

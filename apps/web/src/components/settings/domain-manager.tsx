'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';

import { ArrowsClockwise, Check, Globe, Trash } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { FormError } from '@/components/ui/form-error';
import { Sheet } from '@/components/ui/sheet';
import { apiRequest, type ApiFailure } from '@/lib/client-api';
import type { DomainRecord } from '@/lib/links';

/**
 * Short domains, and the four things an operator does to one.
 *
 * The API has carried register, verify, promote and delete since the domain
 * work landed; the settings page rendered a read-only list against it, so a
 * multi-domain instance could not be configured through the interface at all.
 */
export function DomainManager({ domains }: { domains: DomainRecord[] }) {
  const router = useRouter();
  const [host, setHost] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const [confirming, setConfirming] = useState<DomainRecord | null>(null);

  // Deleting a domain is the one operation here the security contract puts
  // behind recent authentication, so a 423 is a request to prove it is still
  // you rather than a failure to report as one.
  function report(result: ApiFailure) {
    if (result.status === 423) {
      toast.error('Sign in again to remove a domain', {
        id: 'recent-auth',
        description: 'This action needs a recent sign-in.',
      });

      return;
    }

    setFailure(result);
  }

  async function add(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy('add');
    setFailure(null);

    const result = await apiRequest('/api/v1/domains', { method: 'POST', body: { host } });

    setBusy(null);

    if (!result.ok) {
      report(result);

      return;
    }

    setHost('');
    toast.success('Domain added', {
      id: 'domain-add',
      description: 'Point it at this instance, then check it.',
    });
    router.refresh();
  }

  async function check(domain: DomainRecord) {
    setBusy(domain.id);
    setFailure(null);

    const result = await apiRequest<{ verified: boolean; failure: string | null }>(
      `/api/v1/domains/${domain.id}/verify`,
      { method: 'POST' },
    );

    setBusy(null);

    // An unverified domain answers 422 carrying the reason. That is the useful
    // outcome of a check, not an error to swallow.
    if (!result.ok) {
      toast.error(`${domain.host} did not verify`, {
        id: `verify-${domain.id}`,
        description: result.message,
      });
      router.refresh();

      return;
    }

    toast.success(`${domain.host} verified`, { id: `verify-${domain.id}` });
    router.refresh();
  }

  async function promote(domain: DomainRecord) {
    setBusy(domain.id);
    setFailure(null);

    const result = await apiRequest(`/api/v1/domains/${domain.id}/promote`, { method: 'POST' });

    setBusy(null);

    if (!result.ok) {
      report(result);

      return;
    }

    toast.success(`${domain.host} is now primary`, { id: `promote-${domain.id}` });
    router.refresh();
  }

  async function remove(domain: DomainRecord) {
    setBusy(domain.id);
    setFailure(null);

    const result = await apiRequest(`/api/v1/domains/${domain.id}`, {
      method: 'DELETE',
      body: { confirm_link_deletion: domain.serves_links },
    });

    setBusy(null);
    setConfirming(null);

    if (!result.ok) {
      report(result);

      return;
    }

    toast.success(`${domain.host} removed`, { id: `remove-${domain.id}` });
    router.refresh();
  }

  return (
    <div className="flex flex-col gap-5">
      <FormError failure={failure} />

      {domains.length > 0 ? (
        <ul className="border-border divide-border divide-y rounded-(--radius-token) border">
          {domains.map((domain) => (
            <li
              key={domain.id}
              className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
              data-testid="domain-row"
              data-host={domain.host}
              data-primary={domain.primary ? 'true' : 'false'}
            >
              <div className="min-w-0">
                <p className="text-ink flex items-center gap-2 text-sm">
                  <Globe size={15} className="text-ink-muted shrink-0" />
                  <span className="tabular truncate">{domain.host}</span>
                </p>

                <p className="text-ink-muted mt-1 flex flex-wrap items-center gap-x-3 text-xs">
                  {domain.primary ? <span>primary</span> : null}
                  <span className={domain.verified ? undefined : 'text-critical'}>
                    {domain.verified ? 'verified' : 'unverified'}
                  </span>
                  <span>
                    {domain.link_count} {domain.link_count === 1 ? 'link' : 'links'}
                  </span>
                  {domain.last_failure !== null ? <span>{domain.last_failure}</span> : null}
                </p>
              </div>

              <div className="flex shrink-0 items-center gap-2">
                <Button
                  intent="ghost"
                  size="sm"
                  disabled={busy !== null}
                  onClick={() => void check(domain)}
                  data-testid={`verify-${domain.host}`}
                >
                  <ArrowsClockwise size={14} />
                  Check
                </Button>

                {/* Promotion is offered only where it is possible: an unverified
                    host cannot be primary, and the current primary has nowhere
                    to go. */}
                {domain.verified && !domain.primary ? (
                  <Button
                    intent="ghost"
                    size="sm"
                    disabled={busy !== null}
                    onClick={() => void promote(domain)}
                    data-testid={`promote-${domain.host}`}
                  >
                    <Check size={14} />
                    Make primary
                  </Button>
                ) : null}

                {domain.primary ? null : (
                  <Button
                    intent="ghost"
                    size="sm"
                    disabled={busy !== null}
                    onClick={() => setConfirming(domain)}
                    data-testid={`remove-${domain.host}`}
                  >
                    <Trash size={14} />
                    Remove
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-ink-muted text-sm">No domains registered.</p>
      )}

      <form className="flex flex-wrap items-end gap-3" onSubmit={add} data-testid="add-domain">
        <div className="min-w-56 flex-1">
          <Field
            label="Add a domain"
            hint="A short domain must resolve to this instance before it serves anything."
            error={failure?.errors.host?.[0]}
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                className="tabular"
                spellCheck={false}
                placeholder="go.example.com"
                value={host}
                onChange={(event) => setHost(event.target.value)}
                data-testid="domain-host"
              />
            )}
          </Field>
        </div>

        <Button
          type="submit"
          intent="primary"
          size="md"
          disabled={busy !== null || host.trim() === ''}
          data-testid="save-domain"
        >
          {busy === 'add' ? 'Adding' : 'Add'}
        </Button>
      </form>

      <Sheet
        open={confirming !== null}
        onOpenChange={(next) => setConfirming(next ? confirming : null)}
        title="Remove this domain?"
        description={
          confirming === null
            ? undefined
            : confirming.serves_links
              ? `${confirming.host} serves ${confirming.link_count} ${confirming.link_count === 1 ? 'link' : 'links'}. Removing it destroys them, and the short URLs stop resolving.`
              : `${confirming.host} serves no links.`
        }
      >
        <div className="flex items-center gap-3">
          <Button
            intent="critical"
            size="md"
            disabled={busy !== null}
            onClick={() => {
              if (confirming !== null) {
                void remove(confirming);
              }
            }}
            data-testid="confirm-remove-domain"
          >
            Remove
          </Button>

          <Button intent="ghost" size="md" onClick={() => setConfirming(null)}>
            Cancel
          </Button>
        </div>
      </Sheet>
    </div>
  );
}

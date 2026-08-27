'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';

import { ArrowsClockwise, Copy, Plus, Prohibit, Trash } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { FormError } from '@/components/ui/form-error';
import { Sheet } from '@/components/ui/sheet';
import { apiRequest, type ApiFailure } from '@/lib/client-api';

export type WebhookEndpoint = {
  id: string;
  name: string;
  url: string;
  events: string[];
  disabled: boolean;
};

export type Delivery = {
  id: string;
  event: string;
  status: string;
  attempts: number;
  status_code: number | null;
  error: string | null;
  created_at: string | null;
};

const EVENT_LABELS: Record<string, string> = {
  'click.recorded': 'Click recorded',
  'link.created': 'Link created',
  'link.updated': 'Link changed',
  'link.deleted': 'Link deleted',
};

export function WebhookManager({
  endpoints,
  events,
}: {
  endpoints: WebhookEndpoint[];
  events: string[];
}) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const [issued, setIssued] = useState<{ name: string; secret: string } | null>(null);
  const [deliveries, setDeliveries] = useState<{ id: string; rows: Delivery[] } | null>(null);

  function report(result: ApiFailure) {
    if (result.status === 423) {
      toast.error('Sign in again to change a signing secret', {
        id: 'recent-auth',
        description: 'This action needs a recent sign-in.',
      });

      return;
    }

    setFailure(result);
  }

  async function create(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);

    setBusy(true);
    setFailure(null);

    const result = await apiRequest<{ secret: string; endpoint: WebhookEndpoint }>(
      '/api/v1/webhooks',
      {
        method: 'POST',
        body: {
          name: data.get('name'),
          url: data.get('url'),
          events: data.getAll('events'),
        },
      },
    );

    setBusy(false);

    if (!result.ok) {
      report(result);

      return;
    }

    // Shown once, here, from the response. It is on no other representation and
    // cannot be fetched again — rotating issues a new one.
    setIssued({ name: result.data.endpoint.name, secret: result.data.secret });
    setOpen(false);
    router.refresh();
  }

  async function rotate(endpoint: WebhookEndpoint) {
    const result = await apiRequest<{ secret: string }>(`/api/v1/webhooks/${endpoint.id}/rotate`, {
      method: 'POST',
    });

    if (!result.ok) {
      report(result);

      return;
    }

    setIssued({ name: endpoint.name, secret: result.data.secret });
    router.refresh();
  }

  async function toggle(endpoint: WebhookEndpoint) {
    const result = await apiRequest(`/api/v1/webhooks/${endpoint.id}`, {
      method: 'PATCH',
      body: { disabled: !endpoint.disabled },
    });

    if (!result.ok) {
      report(result);

      return;
    }

    router.refresh();
  }

  async function remove(endpoint: WebhookEndpoint) {
    const result = await apiRequest(`/api/v1/webhooks/${endpoint.id}`, { method: 'DELETE' });

    if (!result.ok) {
      report(result);

      return;
    }

    toast.success(`${endpoint.name} removed`, { id: `webhook-${endpoint.id}` });
    router.refresh();
  }

  async function loadDeliveries(endpoint: WebhookEndpoint) {
    const result = await apiRequest<{ deliveries: Delivery[] }>(
      `/api/v1/webhooks/${endpoint.id}/deliveries`,
    );

    if (result.ok) {
      setDeliveries({ id: endpoint.id, rows: result.data.deliveries });
    }
  }

  async function replay(delivery: Delivery, endpoint: WebhookEndpoint) {
    const result = await apiRequest(`/api/v1/webhooks/deliveries/${delivery.id}/replay`, {
      method: 'POST',
    });

    if (!result.ok) {
      report(result);

      return;
    }

    toast.success('Replayed', { id: `replay-${delivery.id}` });
    await loadDeliveries(endpoint);
  }

  return (
    <div className="flex flex-col gap-5">
      <FormError failure={failure} />

      {issued !== null ? (
        <div
          className="border-border rounded-(--radius-token) border p-3"
          role="alert"
          data-testid="issued-secret"
        >
          <p className="text-ink text-sm">
            The signing secret for {issued.name}. This is the only time it is shown.
          </p>

          <div className="mt-2 flex items-center gap-2">
            <code className="tabular text-ink-muted min-w-0 flex-1 truncate text-xs">
              {issued.secret}
            </code>

            <Button
              intent="outline"
              size="sm"
              onClick={() => {
                void navigator.clipboard.writeText(issued.secret);
                toast.success('Copied', { id: 'secret-copied' });
              }}
            >
              <Copy size={14} />
              Copy
            </Button>

            <Button intent="ghost" size="sm" onClick={() => setIssued(null)}>
              Done
            </Button>
          </div>
        </div>
      ) : null}

      {endpoints.length === 0 ? (
        <p className="text-ink-muted text-sm">No endpoints. Nothing is delivered anywhere.</p>
      ) : (
        <ul className="border-border divide-border divide-y rounded-(--radius-token) border">
          {endpoints.map((endpoint) => (
            <li key={endpoint.id} className="px-4 py-3" data-testid="webhook-row">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-ink text-sm">{endpoint.name}</p>
                  <p className="text-ink-muted tabular mt-1 truncate text-xs">{endpoint.url}</p>
                  <p className="text-ink-muted mt-1 text-xs">
                    {endpoint.events.map((event) => EVENT_LABELS[event] ?? event).join(', ')}
                    {endpoint.disabled ? ' — disabled' : ''}
                  </p>
                </div>

                <div className="flex shrink-0 items-center gap-2">
                  <Button
                    intent="ghost"
                    size="sm"
                    onClick={() => void loadDeliveries(endpoint)}
                    data-testid={`deliveries-${endpoint.name}`}
                  >
                    Deliveries
                  </Button>

                  <Button intent="ghost" size="sm" onClick={() => void rotate(endpoint)}>
                    <ArrowsClockwise size={14} />
                    Rotate
                  </Button>

                  <Button intent="ghost" size="sm" onClick={() => void toggle(endpoint)}>
                    <Prohibit size={14} />
                    {endpoint.disabled ? 'Enable' : 'Disable'}
                  </Button>

                  <Button intent="ghost" size="sm" onClick={() => void remove(endpoint)}>
                    <Trash size={14} />
                    Remove
                  </Button>
                </div>
              </div>

              {deliveries?.id === endpoint.id ? (
                <ul className="border-border mt-3 border-t pt-3" data-testid="delivery-log">
                  {deliveries.rows.length === 0 ? (
                    <li className="text-ink-muted text-xs">Nothing delivered yet.</li>
                  ) : (
                    deliveries.rows.map((delivery) => (
                      <li
                        key={delivery.id}
                        className="flex items-center justify-between gap-3 py-1 text-xs"
                      >
                        <span className="text-ink-muted tabular truncate">
                          {delivery.event} — {delivery.status}
                          {delivery.status_code === null ? '' : ` (${delivery.status_code})`}
                          {delivery.attempts > 1 ? ` after ${delivery.attempts} attempts` : ''}
                        </span>

                        <Button
                          intent="ghost"
                          size="sm"
                          onClick={() => void replay(delivery, endpoint)}
                        >
                          Replay
                        </Button>
                      </li>
                    ))
                  )}
                </ul>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      <div>
        <Button intent="primary" size="md" onClick={() => setOpen(true)} data-testid="new-webhook">
          <Plus size={14} />
          Add an endpoint
        </Button>
      </div>

      <Sheet
        open={open}
        onOpenChange={setOpen}
        title="Add an endpoint"
        description="Deliveries are signed. The secret is shown once, when the endpoint is created."
      >
        <form className="flex flex-col gap-5" onSubmit={create}>
          <FormError failure={failure} />

          <Field label="Name" error={failure?.errors.name?.[0]}>
            {({ id, describedBy }) => (
              <Input id={id} name="name" aria-describedby={describedBy} required maxLength={80} />
            )}
          </Field>

          <Field
            label="URL"
            hint="Must be https, and must not resolve to a private or loopback address."
            error={failure?.errors.url?.[0]}
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                name="url"
                type="url"
                inputMode="url"
                spellCheck={false}
                aria-describedby={describedBy}
                required
                data-testid="webhook-url"
              />
            )}
          </Field>

          <fieldset className="flex flex-col gap-2">
            <legend className="text-ink mb-1 text-sm font-medium">Events</legend>

            {events.map((event) => (
              <label key={event} className="text-ink flex items-center gap-2 text-sm">
                <input type="checkbox" name="events" value={event} data-testid={`event-${event}`} />
                {EVENT_LABELS[event] ?? event}
              </label>
            ))}
          </fieldset>

          <div className="flex items-center gap-3">
            <Button
              intent="primary"
              size="md"
              type="submit"
              disabled={busy}
              data-testid="save-webhook"
            >
              {busy ? 'Adding' : 'Add'}
            </Button>
            <Button intent="ghost" size="md" type="button" onClick={() => setOpen(false)}>
              Cancel
            </Button>
          </div>
        </form>
      </Sheet>
    </div>
  );
}

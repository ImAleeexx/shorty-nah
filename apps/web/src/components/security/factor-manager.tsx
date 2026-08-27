'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';

import { Copy, LockKey, Plus, Trash } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { FormError } from '@/components/ui/form-error';
import { apiRequest, type ApiFailure } from '@/lib/client-api';
import { useMounted } from '@/lib/use-mounted';
import { createPasskey, passkeysAvailable } from '@/lib/webauthn';

export type Credential = {
  id: string;
  type: string;
  name: string;
  added_at: string | null;
  last_used_at: string | null;
};

type Enrolment = { id: string; secret: string; uri: string };

/**
 * Enrolling and removing second factors.
 *
 * This is the screen whose absence made the instance-wide requirement a trap.
 * The API has carried enrolment since phase 17, and its routes deliberately sit
 * above the enforcement so a confined account can reach them — but nothing in
 * the interface ever called them. Turning the requirement on with nobody
 * enrolled locked every operator out of their own instance, with a refusal on
 * screen and nowhere to go.
 */
export function FactorManager({
  credentials,
  required,
  enrolled,
  recoveryRemaining,
}: {
  credentials: Credential[];
  required: boolean;
  enrolled: boolean;
  recoveryRemaining: number;
}) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const [enrolment, setEnrolment] = useState<Enrolment | null>(null);
  const [code, setCode] = useState('');
  const [recovery, setRecovery] = useState<string[] | null>(null);

  // Gated on hydration, not read in a useState initializer. The initializer runs
  // on the server (no window, false) and again during the client's hydration
  // render (true), so the button would exist in one tree and not the other —
  // the same mismatch the colour-mode toggle had, and React resolves it by
  // keeping the server's markup, which is the version without the button.
  const mounted = useMounted();
  const passkeysSupported = mounted && passkeysAvailable();

  // The list is held locally as well as rendered from the server.
  // `router.refresh()` alone does not reliably surface a passkey that was just
  // registered: the credential is stored and confirmed, and the row appears only
  // after a full reload — which reads as the registration having failed.
  // Re-reading the list after every change makes the screen agree with the
  // instance immediately, and the refresh still runs so the server view catches
  // up behind it.
  const [rows, setRows] = useState(credentials);
  const [shownFor, setShownFor] = useState(credentials);

  if (shownFor !== credentials) {
    setShownFor(credentials);
    setRows(credentials);
  }

  async function reload() {
    const result = await apiRequest<{ credentials: Credential[] }>('/api/v1/auth/two-factor');

    if (result.ok) {
      setRows(result.data.credentials);
    }

    router.refresh();
  }

  function report(result: ApiFailure) {
    if (result.status === 423) {
      toast.error('Sign in again to change a second factor', {
        id: 'recent-auth',
        description: 'This action needs a recent sign-in.',
      });

      return;
    }

    setFailure(result);
  }

  async function begin() {
    setBusy(true);
    setFailure(null);

    const result = await apiRequest<Enrolment>('/api/v1/auth/two-factor', {
      method: 'POST',
      body: { name: 'Authenticator app' },
    });

    setBusy(false);

    if (!result.ok) {
      report(result);

      return;
    }

    setEnrolment(result.data);
  }

  async function confirm(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (enrolment === null) {
      return;
    }

    setBusy(true);
    setFailure(null);

    const result = await apiRequest<{ recovery_codes: string[] }>(
      `/api/v1/auth/two-factor/${enrolment.id}/confirm`,
      { method: 'POST', body: { code } },
    );

    setBusy(false);

    if (!result.ok) {
      report(result);

      return;
    }

    setEnrolment(null);
    setCode('');

    // Issued once, on the first factor only. Held on screen until dismissed
    // rather than shown in a toast: these are the way back in, and a message
    // that disappears on its own is the wrong container for them.
    if (result.data.recovery_codes.length > 0) {
      setRecovery(result.data.recovery_codes);
    }

    toast.success('Second factor enrolled', { id: 'two-factor' });
    await reload();
  }

  async function addPasskey() {
    setBusy(true);
    setFailure(null);

    const options = await apiRequest<Record<string, unknown>>(
      '/api/v1/auth/two-factor/passkey/options',
      { method: 'POST' },
    );

    if (!options.ok) {
      setBusy(false);
      report(options);

      return;
    }

    let credential: string;

    try {
      credential = await createPasskey(
        options.data as unknown as Parameters<typeof createPasskey>[0],
      );
    } catch {
      setBusy(false);

      // A cancelled prompt is the ordinary case, not a failure worth a banner:
      // the operator changed their mind or the key was not present.
      toast.error('No passkey was registered', {
        id: 'passkey',
        description: 'The prompt was dismissed, or this device offered no key.',
      });

      return;
    }

    // recovery_codes is null for anything but the account's first factor — they
    // are issued once, not per factor — so this is nullable, not optional.
    // Treating it as merely optional made `null.length` throw after a successful
    // registration, which skipped the refresh and left the new passkey invisible
    // until a full reload.
    const result = await apiRequest<{ recovery_codes: string[] | null }>(
      '/api/v1/auth/two-factor/passkey',
      { method: 'POST', body: { name: 'Passkey', credential } },
    );

    setBusy(false);

    if (!result.ok) {
      report(result);

      return;
    }

    const issued = result.data.recovery_codes;

    if (issued !== null && issued.length > 0) {
      setRecovery(issued);
    }

    toast.success('Passkey registered', { id: 'passkey' });
    await reload();
  }

  async function remove(credential: Credential) {
    setBusy(true);
    setFailure(null);

    const result = await apiRequest(`/api/v1/auth/two-factor/${credential.id}`, {
      method: 'DELETE',
    });

    setBusy(false);

    if (!result.ok) {
      report(result);

      return;
    }

    toast.success(`${credential.name} removed`, { id: `factor-${credential.id}` });
    await reload();
  }

  return (
    <div className="flex flex-col gap-5">
      <FormError failure={failure} />

      {required && !enrolled ? (
        <p
          className="border-border text-ink rounded-(--radius-token) border p-3 text-sm"
          role="status"
          data-testid="enrolment-required"
        >
          This instance requires a second factor. Enrol one below to reach the rest of the
          interface.
        </p>
      ) : null}

      {recovery === null ? null : (
        <div
          className="border-border rounded-(--radius-token) border p-3"
          role="alert"
          data-testid="recovery-codes"
        >
          <p className="text-ink text-sm">
            Your recovery codes. Each works once, and this is the only time they are shown.
          </p>

          <ul className="tabular text-ink-muted mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
            {recovery.map((entry) => (
              <li key={entry}>{entry}</li>
            ))}
          </ul>

          <div className="mt-3 flex items-center gap-2">
            <Button
              intent="outline"
              size="sm"
              onClick={() => {
                void navigator.clipboard.writeText(recovery.join('\n'));
                toast.success('Copied', { id: 'recovery-copied' });
              }}
            >
              <Copy size={14} />
              Copy
            </Button>

            <Button intent="ghost" size="sm" onClick={() => setRecovery(null)}>
              I have saved them
            </Button>
          </div>
        </div>
      )}

      {rows.length === 0 ? (
        <p className="text-ink-muted text-sm">No second factor on this account.</p>
      ) : (
        <ul className="border-border divide-border divide-y rounded-(--radius-token) border">
          {rows.map((credential) => (
            <li
              key={credential.id}
              className="flex items-center justify-between gap-4 px-4 py-3"
              data-testid="factor-row"
            >
              <div className="min-w-0">
                <p className="text-ink flex items-center gap-2 text-sm">
                  <LockKey size={15} className="text-ink-muted shrink-0" />
                  {credential.name}
                </p>
                <p className="text-ink-muted mt-1 text-xs">
                  {credential.type === 'totp' ? 'Authenticator app' : 'Passkey'}
                  {credential.last_used_at === null ? ' · never used' : ''}
                </p>
              </div>

              <Button
                intent="ghost"
                size="sm"
                disabled={busy}
                onClick={() => void remove(credential)}
                data-testid={`remove-factor-${credential.id}`}
              >
                <Trash size={14} />
                Remove
              </Button>
            </li>
          ))}
        </ul>
      )}

      {rows.length > 0 && recoveryRemaining > 0 ? (
        <p className="text-ink-muted text-xs">
          <span className="tabular">{recoveryRemaining}</span> recovery{' '}
          {recoveryRemaining === 1 ? 'code' : 'codes'} remaining.
        </p>
      ) : null}

      {enrolment === null ? (
        <div className="flex flex-wrap items-center gap-2">
          <Button
            intent="primary"
            size="md"
            disabled={busy}
            onClick={() => void begin()}
            data-testid="begin-enrolment"
          >
            <Plus size={14} />
            Add an authenticator app
          </Button>

          {passkeysSupported ? (
            <Button
              intent="outline"
              size="md"
              disabled={busy}
              onClick={() => void addPasskey()}
              data-testid="add-passkey"
            >
              <LockKey size={14} />
              Add a passkey
            </Button>
          ) : null}
        </div>
      ) : (
        <form
          className="border-border flex flex-col gap-4 rounded-(--radius-token) border p-4"
          onSubmit={confirm}
          data-testid="enrolment-form"
        >
          <p className="text-ink text-sm">
            Scan this with your authenticator app, then enter the code it shows.
          </p>

          {/* Rendered by the API from the credential, not from a URI this page
              hands it: a renderer that draws whatever it is given is a way to
              make the instance serve an arbitrary payload as an image under its
              own origin. The image carries the shared secret, so it is fetched
              fresh and never cached. */}
          {/* eslint-disable-next-line @next/next/no-img-element -- a no-store
              image generated per enrolment, not an asset. */}
          <img
            src={`/api/v1/auth/two-factor/${enrolment.id}/qr`}
            alt="Scan this code with an authenticator app"
            className="border-border size-40 rounded-(--radius-token-sm) border bg-white p-2"
            data-testid="enrolment-qr"
          />

          <p className="text-ink-muted text-xs">No camera? Enter this secret by hand instead.</p>

          {/* The secret as text, not only a QR code. A QR needs a camera and a
              second device; the secret works with a password manager on the
              machine already in front of the operator. */}
          <code
            className="tabular border-border text-ink-muted rounded-(--radius-token-sm) border px-3 py-2 text-xs break-all"
            data-testid="enrolment-secret"
          >
            {enrolment.secret}
          </code>

          <div className="flex items-center gap-2">
            <Button
              type="button"
              intent="outline"
              size="sm"
              onClick={() => {
                void navigator.clipboard.writeText(enrolment.secret);
                toast.success('Copied', { id: 'secret-copied' });
              }}
            >
              <Copy size={14} />
              Copy the secret
            </Button>

            <a
              href={enrolment.uri}
              className="text-ink-muted hover:text-ink text-xs underline underline-offset-2"
            >
              Open in an authenticator
            </a>
          </div>

          <Field label="Code from the app" error={failure?.errors.code?.[0]}>
            {({ id, describedBy }) => (
              <Input
                id={id}
                className="tabular"
                inputMode="numeric"
                autoComplete="one-time-code"
                aria-describedby={describedBy}
                value={code}
                onChange={(event) => setCode(event.target.value)}
                data-testid="enrolment-code"
                required
              />
            )}
          </Field>

          <div className="flex items-center gap-3">
            <Button
              intent="primary"
              size="md"
              type="submit"
              disabled={busy}
              data-testid="confirm-enrolment"
            >
              {busy ? 'Confirming' : 'Confirm'}
            </Button>

            <Button
              intent="ghost"
              size="md"
              type="button"
              onClick={() => {
                setEnrolment(null);
                setCode('');
              }}
            >
              Cancel
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}

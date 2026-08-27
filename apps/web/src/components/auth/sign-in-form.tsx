'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { LockKey } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { FormError } from '@/components/ui/form-error';
import { apiRequest, type ApiFailure } from '@/lib/client-api';
import { useMounted } from '@/lib/use-mounted';
import { authenticateWithPasskey, passkeysAvailable } from '@/lib/webauthn';

export function SignInForm() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  // A correct password does not sign anyone in when a second factor is
  // enrolled, so the form has a second state rather than a second page: the
  // pending account lives in the session, not in a URL.
  const [challenge, setChallenge] = useState(false);
  // Which kinds of factor this account holds, so the challenge asks for one it
  // has. An account whose only factor is a passkey was previously shown an
  // authenticator field it could never satisfy.
  const [methods, setMethods] = useState<string[]>([]);
  const [usingRecovery, setUsingRecovery] = useState(false);

  const mounted = useMounted();

  function arrive() {
    // refresh() before push(): the destination is a server component whose
    // cached render was produced for a signed-out viewer.
    router.refresh();
    router.push('/');
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);

    setBusy(true);
    setFailure(null);

    const result = await apiRequest<{ two_factor_required?: boolean; methods?: string[] }>(
      '/api/v1/auth/session',
      {
        method: 'POST',
        body: { email: data.get('email'), password: data.get('password') },
      },
    );

    setBusy(false);

    if (!result.ok) {
      setFailure(result);

      return;
    }

    if (result.data?.two_factor_required === true) {
      setMethods(result.data.methods ?? []);
      setUsingRecovery(false);
      setChallenge(true);

      return;
    }

    arrive();
  }

  async function satisfyWithPasskey() {
    setBusy(true);
    setFailure(null);

    const options = await apiRequest<Record<string, unknown>>(
      '/api/v1/auth/two-factor/challenge/passkey',
      { method: 'POST' },
    );

    if (!options.ok) {
      setBusy(false);
      setFailure(options);

      return;
    }

    let credential: string;

    try {
      credential = await authenticateWithPasskey(
        options.data as unknown as Parameters<typeof authenticateWithPasskey>[0],
      );
    } catch {
      setBusy(false);
      setFailure({
        ok: false,
        status: 0,
        message: 'No passkey was offered. Try again, or use a recovery code.',
        errors: {},
      });

      return;
    }

    const result = await apiRequest('/api/v1/auth/two-factor/challenge', {
      method: 'POST',
      body: { credential },
    });

    setBusy(false);

    if (!result.ok) {
      setFailure(result);

      if (result.status === 410) {
        setChallenge(false);
      }

      return;
    }

    arrive();
  }

  async function satisfy(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);
    const recovery = String(data.get('recovery_code') ?? '').trim();

    setBusy(true);
    setFailure(null);

    const result = await apiRequest('/api/v1/auth/two-factor/challenge', {
      method: 'POST',
      body: recovery === '' ? { code: data.get('code') } : { recovery_code: recovery },
    });

    setBusy(false);

    if (!result.ok) {
      setFailure(result);

      // An expired challenge cannot be retried; the password step starts again.
      if (result.status === 410) {
        setChallenge(false);
      }

      return;
    }

    arrive();
  }

  if (challenge) {
    const hasPasskey = methods.includes('webauthn');
    const hasAuthenticator = methods.includes('totp');

    return (
      /*
       * Keyed apart from the password form, and that key is a security fix
       * rather than a tidy-up.
       *
       * Both branches render a <form>, a <FormError>, then a <Field>. React
       * reconciles by position and type, so it kept the password <input> DOM
       * node and reused it as the code field — the typed password stayed in the
       * box and the type flipped from password to text, putting it on screen in
       * clear text and submitting it as the second factor. Distinct keys make
       * React unmount one tree and mount the other.
       */
      <form
        key="challenge"
        className="flex flex-col gap-5"
        onSubmit={satisfy}
        data-testid="two-factor-challenge"
      >
        <FormError failure={failure} />

        {/* Only what this account can actually satisfy. */}
        {hasPasskey && !usingRecovery ? (
          <>
            <p className="text-ink-muted text-sm">
              {hasAuthenticator
                ? 'Use your passkey, or enter a code from your authenticator.'
                : 'Use the passkey registered to this account.'}
            </p>

            {mounted && passkeysAvailable() ? (
              <div>
                <Button
                  intent="primary"
                  size="lg"
                  type="button"
                  disabled={busy}
                  onClick={() => void satisfyWithPasskey()}
                  data-testid="use-passkey"
                >
                  <LockKey size={16} />
                  {busy ? 'Waiting' : 'Use a passkey'}
                </Button>
              </div>
            ) : (
              <p className="text-critical text-sm" role="alert">
                This browser cannot use passkeys. Use a recovery code instead.
              </p>
            )}
          </>
        ) : null}

        {hasAuthenticator && !usingRecovery ? (
          <Field label="Authenticator code" error={failure?.errors.code?.[0]}>
            {({ id, describedBy }) => (
              <Input
                id={id}
                name="code"
                className="tabular"
                inputMode="numeric"
                autoComplete="one-time-code"
                aria-describedby={describedBy}
                autoFocus
                data-testid="two-factor-code"
              />
            )}
          </Field>
        ) : null}

        {usingRecovery ? (
          <Field
            label="Recovery code"
            hint="Single use. Each one works once."
            error={failure?.errors.code?.[0]}
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                name="recovery_code"
                className="tabular"
                autoComplete="off"
                aria-describedby={describedBy}
                autoFocus
                data-testid="recovery-code"
              />
            )}
          </Field>
        ) : null}

        <div className="flex flex-wrap items-center gap-3">
          {/* The submit button is for the fields; a passkey has its own control
              above and needs no form submission. */}
          {usingRecovery || hasAuthenticator ? (
            <Button
              intent={usingRecovery || !hasPasskey ? 'primary' : 'outline'}
              size="lg"
              type="submit"
              disabled={busy}
              data-testid="submit-two-factor"
            >
              {busy ? 'Checking' : 'Continue'}
            </Button>
          ) : null}

          {/* Behind a control rather than a field on the page. A recovery code
              is the way in when the factor is unreachable, not the ordinary
              route, and offering it every time invites its use over the factor
              it exists to back up. */}
          <button
            type="button"
            className="text-ink-muted hover:text-ink text-sm underline underline-offset-2"
            onClick={() => {
              setUsingRecovery((current) => !current);
              setFailure(null);
            }}
            data-testid="toggle-recovery"
          >
            {usingRecovery ? 'Back to my second factor' : 'Use a recovery code instead'}
          </button>
        </div>
      </form>
    );
  }

  return (
    <form key="password" className="flex flex-col gap-5" onSubmit={submit}>
      <FormError failure={failure} />

      <Field label="Email" error={failure?.errors.email?.[0]}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            name="email"
            type="email"
            inputMode="email"
            autoComplete="username"
            autoFocus
            spellCheck={false}
            placeholder="you@example.com"
            aria-describedby={describedBy}
            required
          />
        )}
      </Field>

      <Field label="Password" error={failure?.errors.password?.[0]}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            name="password"
            type="password"
            autoComplete="current-password"
            aria-describedby={describedBy}
            required
          />
        )}
      </Field>

      {/* Full width, because it is the only thing to do on this page and a
          button sized to its label leaves the form looking unfinished at the
          bottom. */}
      <Button
        intent="primary"
        size="lg"
        type="submit"
        className="w-full"
        disabled={busy}
        data-testid="sign-in"
      >
        {busy ? 'Signing in' : 'Sign in'}
      </Button>
    </form>
  );
}

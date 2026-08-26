'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { apiRequest, type ApiFailure } from '@/lib/client-api';

export function SignInForm() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  // A correct password does not sign anyone in when a second factor is
  // enrolled, so the form has a second state rather than a second page: the
  // pending account lives in the session, not in a URL.
  const [challenge, setChallenge] = useState(false);

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

    const result = await apiRequest<{ two_factor_required?: boolean }>('/api/v1/auth/session', {
      method: 'POST',
      body: { email: data.get('email'), password: data.get('password') },
    });

    setBusy(false);

    if (!result.ok) {
      setFailure(result);

      return;
    }

    if (result.data?.two_factor_required === true) {
      setChallenge(true);

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
    return (
      <form className="flex flex-col gap-5" onSubmit={satisfy} data-testid="two-factor-challenge">
        <p className="text-ink-muted text-sm">
          Enter the code from your authenticator, or a recovery code if you cannot reach it.
        </p>

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

        <Field label="Recovery code" hint="Single use. Each one works once.">
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="recovery_code"
              className="tabular"
              autoComplete="off"
              aria-describedby={describedBy}
              data-testid="recovery-code"
            />
          )}
        </Field>

        <div>
          <Button
            intent="primary"
            size="lg"
            type="submit"
            disabled={busy}
            data-testid="submit-two-factor"
          >
            {busy ? 'Checking' : 'Continue'}
          </Button>
        </div>
      </form>
    );
  }

  return (
    <form className="flex flex-col gap-5" onSubmit={submit}>
      <Field label="Email" error={failure?.errors.email?.[0]}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            name="email"
            type="email"
            autoComplete="username"
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

      <div>
        <Button intent="primary" size="lg" type="submit" disabled={busy} data-testid="sign-in">
          {busy ? 'Signing in' : 'Sign in'}
        </Button>
      </div>
    </form>
  );
}

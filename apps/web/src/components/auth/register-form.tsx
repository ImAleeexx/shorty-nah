'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { apiRequest, type ApiFailure } from '@/lib/client-api';

export function RegisterForm({ requiresInvitation }: { requiresInvitation: boolean }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);

    setBusy(true);
    setFailure(null);

    const result = await apiRequest('/api/v1/auth/register', {
      method: 'POST',
      body: {
        name: data.get('name'),
        email: data.get('email'),
        password: data.get('password'),
        invitation_token: requiresInvitation ? data.get('invitation_token') : null,
      },
    });

    if (!result.ok) {
      setBusy(false);
      setFailure(result);

      return;
    }

    router.refresh();
    router.push('/');
  }

  return (
    <form className="flex flex-col gap-5" onSubmit={submit}>
      {failure !== null && Object.keys(failure.errors).length === 0 ? (
        <p className="text-critical text-sm" role="alert">
          {failure.message}
        </p>
      ) : null}

      <Field label="Name" error={failure?.errors.name?.[0]}>
        {({ id, describedBy }) => (
          <Input id={id} name="name" autoComplete="name" aria-describedby={describedBy} required />
        )}
      </Field>

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

      <Field
        label="Password"
        hint="Long beats complicated. A passphrase is the easiest way to reach the length."
        error={failure?.errors.password?.[0]}
      >
        {({ id, describedBy }) => (
          <Input
            id={id}
            name="password"
            type="password"
            autoComplete="new-password"
            aria-describedby={describedBy}
            required
          />
        )}
      </Field>

      {requiresInvitation ? (
        <Field label="Invitation code" error={failure?.errors.invitation_token?.[0]}>
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="invitation_token"
              className="tabular"
              autoComplete="off"
              spellCheck={false}
              aria-describedby={describedBy}
              required
            />
          )}
        </Field>
      ) : null}

      <div>
        <Button intent="primary" size="lg" type="submit" disabled={busy} data-testid="register">
          {busy ? 'Creating account' : 'Create account'}
        </Button>
      </div>
    </form>
  );
}

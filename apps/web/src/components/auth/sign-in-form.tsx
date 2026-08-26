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

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);

    setBusy(true);
    setFailure(null);

    const result = await apiRequest('/api/v1/auth/session', {
      method: 'POST',
      body: { email: data.get('email'), password: data.get('password') },
    });

    if (!result.ok) {
      setBusy(false);
      setFailure(result);

      return;
    }

    // refresh() before push(): the destination is a server component whose
    // cached render was produced for a signed-out viewer.
    router.refresh();
    router.push('/');
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

'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';

import { SignOut } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { apiRequest } from '@/lib/client-api';

export function SignOutButton() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);

  return (
    <Button
      intent="ghost"
      size="icon"
      aria-label="Sign out"
      disabled={busy}
      onClick={async () => {
        setBusy(true);
        await apiRequest('/api/v1/auth/session', { method: 'DELETE' });
        router.refresh();
        router.push('/sign-in');
      }}
    >
      <SignOut size={16} />
    </Button>
  );
}

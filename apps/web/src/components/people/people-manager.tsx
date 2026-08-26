'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';

import { Copy, Plus, Trash } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/field';
import { Sheet } from '@/components/ui/sheet';
import { apiRequest, type ApiFailure } from '@/lib/client-api';
import type { Viewer } from '@/lib/session';

export type Person = {
  id: string;
  name: string;
  email: string;
  role: Viewer['role'];
  disabled: boolean;
};

export type Invitation = {
  id: string;
  email: string;
  role: string;
  expires_at: string | null;
  accepted_at: string | null;
  revoked_at: string | null;
};

const ROLES: Viewer['role'][] = ['owner', 'admin', 'member', 'viewer'];

function state(invitation: Invitation): 'accepted' | 'revoked' | 'outstanding' {
  if (invitation.accepted_at !== null) {
    return 'accepted';
  }

  return invitation.revoked_at === null ? 'outstanding' : 'revoked';
}

export function PeopleManager({
  viewer,
  people,
  invitations,
}: {
  viewer: Viewer;
  people: Person[];
  invitations: Invitation[];
}) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const [issued, setIssued] = useState<{ email: string; token: string } | null>(null);

  /**
   * Membership changes require recent authentication, so a 423 is not a failure
   * to report as one — it is a request to prove it is still you.
   */
  function report(result: ApiFailure) {
    if (result.status === 423) {
      toast.error('Sign in again to change membership', {
        id: 'recent-auth',
        description: 'This action needs a recent sign-in.',
      });

      return;
    }

    setFailure(result);
    toast.error(result.message, { id: 'people-error' });
  }

  async function changeRole(person: Person, role: string) {
    const result = await apiRequest(`/api/v1/users/${person.id}/role`, {
      method: 'PATCH',
      body: { role },
    });

    if (!result.ok) {
      report(result);

      return;
    }

    toast.success(`${person.name} is now ${role}`, { id: `role-${person.id}` });
    router.refresh();
  }

  async function remove(person: Person) {
    const result = await apiRequest(`/api/v1/users/${person.id}`, { method: 'DELETE' });

    if (!result.ok) {
      report(result);

      return;
    }

    toast.success(`${person.name} removed`, { id: `remove-${person.id}` });
    router.refresh();
  }

  async function revoke(invitation: Invitation) {
    const result = await apiRequest(`/api/v1/invitations/${invitation.id}`, { method: 'DELETE' });

    if (!result.ok) {
      report(result);

      return;
    }

    toast.success('Invitation revoked', { id: `revoke-${invitation.id}` });
    router.refresh();
  }

  async function invite(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);

    setBusy(true);
    setFailure(null);

    const result = await apiRequest<{ email: string; token: string }>('/api/v1/invitations', {
      method: 'POST',
      body: { email: data.get('email'), role: data.get('role') },
    });

    setBusy(false);

    if (!result.ok) {
      report(result);

      return;
    }

    // Shown once and never again: only its hash reaches the database.
    setIssued({ email: result.data.email, token: result.data.token });
    setOpen(false);
    router.refresh();
  }

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-ink text-xl font-semibold tracking-tight">People</h1>
          <p className="text-ink-muted mt-1 text-sm">Who can reach this instance, and as what.</p>
        </div>

        <Button
          intent="primary"
          size="md"
          onClick={() => setOpen(true)}
          data-testid="new-invitation"
        >
          <Plus size={15} />
          Invite
        </Button>
      </div>

      {issued ? (
        <div
          className="border-border rounded-(--radius-token) border px-4 py-3"
          data-testid="issued-invitation"
        >
          <p className="text-ink text-sm font-medium">Invitation for {issued.email}</p>
          <p className="text-ink-muted mt-1 text-xs">
            This code is shown once. Only its hash is stored, so it cannot be shown again.
          </p>
          <div className="mt-2 flex items-center gap-2">
            <code className="tabular text-ink text-xs">{issued.token}</code>
            <Button
              intent="ghost"
              size="icon"
              aria-label="Copy invitation code"
              onClick={async () => {
                await navigator.clipboard.writeText(issued.token);
                toast.success('Invitation code copied', { id: 'copy-invitation' });
              }}
            >
              <Copy size={14} />
            </Button>
          </div>
        </div>
      ) : null}

      <section className="flex flex-col gap-3">
        <h2 className="text-ink-subtle text-xs font-medium tracking-wide uppercase">Accounts</h2>

        <ul className="border-border divide-border divide-y rounded-(--radius-token) border">
          {people.map((person) => (
            <li
              key={person.id}
              className="flex flex-wrap items-center gap-4 px-4 py-3"
              data-testid="person-row"
              data-email={person.email}
            >
              <div className="min-w-0 flex-1">
                <p className="text-ink truncate text-sm">{person.name}</p>
                <p className="text-ink-muted truncate text-xs">{person.email}</p>
              </div>

              <Select
                className="w-auto"
                aria-label={`Role for ${person.name}`}
                defaultValue={person.role}
                onChange={(event) => void changeRole(person, event.target.value)}
                disabled={person.id === viewer.id}
                data-testid={`role-${person.email}`}
              >
                {ROLES.map((role) => (
                  <option key={role} value={role}>
                    {role}
                  </option>
                ))}
              </Select>

              <Button
                intent="ghost"
                size="icon"
                aria-label={`Remove ${person.name}`}
                onClick={() => void remove(person)}
                // The account you are signed in as is not removable from here;
                // the last-owner rule is enforced by the API regardless.
                disabled={person.id === viewer.id}
              >
                <Trash size={15} />
              </Button>
            </li>
          ))}
        </ul>
      </section>

      <section className="flex flex-col gap-3">
        <h2 className="text-ink-subtle text-xs font-medium tracking-wide uppercase">Invitations</h2>

        {invitations.length === 0 ? (
          <p className="text-ink-muted text-sm">None outstanding.</p>
        ) : (
          <ul className="border-border divide-border divide-y rounded-(--radius-token) border">
            {invitations.map((invitation) => (
              <li
                key={invitation.id}
                className="flex flex-wrap items-center gap-4 px-4 py-3"
                data-testid="invitation-row"
                data-state={state(invitation)}
              >
                <div className="min-w-0 flex-1">
                  <p className="text-ink truncate text-sm">{invitation.email}</p>
                  <p className="text-ink-muted text-xs">
                    {invitation.role} · {state(invitation)}
                  </p>
                </div>

                {/* Revocation is a state the record keeps, not a deletion: an
                    invitation that was withdrawn is part of the history of who
                    was offered access. */}
                {state(invitation) === 'outstanding' ? (
                  <Button
                    intent="ghost"
                    size="icon"
                    aria-label={`Revoke invitation for ${invitation.email}`}
                    onClick={() => void revoke(invitation)}
                  >
                    <Trash size={15} />
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </section>

      <Sheet
        open={open}
        onOpenChange={setOpen}
        title="Invite someone"
        description="They receive a one-time code. It is shown to you once."
      >
        <form className="flex flex-col gap-5" onSubmit={invite}>
          <Field label="Email" error={failure?.errors.email?.[0]}>
            {({ id, describedBy }) => (
              <Input id={id} name="email" type="email" aria-describedby={describedBy} required />
            )}
          </Field>

          <Field
            label="Role"
            hint="You cannot grant a role above your own."
            error={failure?.errors.role?.[0]}
          >
            {({ id, describedBy }) => (
              <Select id={id} name="role" aria-describedby={describedBy} defaultValue="member">
                {ROLES.map((role) => (
                  <option key={role} value={role}>
                    {role}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          <div>
            <Button
              intent="primary"
              size="md"
              type="submit"
              disabled={busy}
              data-testid="send-invitation"
            >
              {busy ? 'Creating' : 'Create invitation'}
            </Button>
          </div>
        </form>
      </Sheet>
    </div>
  );
}

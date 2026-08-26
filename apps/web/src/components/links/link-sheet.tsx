'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/field';
import { Sheet } from '@/components/ui/sheet';
import { apiRequest, type ApiFailure } from '@/lib/client-api';
import type { DomainRecord, LinkRecord } from '@/lib/links';

/**
 * Creation and editing are one surface.
 *
 * The fields are identical and the difference is entirely in the verb, so two
 * components would be the same component twice with one word changed.
 */
export function LinkSheet({
  open,
  onOpenChange,
  domains,
  link,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  domains: DomainRecord[];
  link: LinkRecord | null;
}) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  const editing = link !== null;

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);
    const tags = String(data.get('tags') ?? '')
      .split(',')
      .map((tag) => tag.trim())
      .filter((tag) => tag !== '');

    const body = {
      destination: data.get('destination'),
      domain: data.get('domain') === '' ? null : data.get('domain'),
      slug: data.get('slug') === '' ? null : data.get('slug'),
      redirect_mode: data.get('redirect_mode') === '' ? null : data.get('redirect_mode'),
      password: data.get('password') === '' ? null : data.get('password'),
      expires_at: data.get('expires_at') === '' ? null : data.get('expires_at'),
      max_clicks: data.get('max_clicks') === '' ? null : Number(data.get('max_clicks')),
      tags,
    };

    setBusy(true);
    setFailure(null);

    const result = await apiRequest(editing ? `/api/v1/links/${link.id}` : '/api/v1/links', {
      method: editing ? 'PATCH' : 'POST',
      body,
    });

    setBusy(false);

    if (!result.ok) {
      setFailure(result);

      return;
    }

    toast.success(editing ? 'Link saved' : 'Link created', { id: 'link-save' });

    onOpenChange(false);
    // The list is server-rendered, so the write is only visible once the server
    // has been asked again.
    router.refresh();
  }

  return (
    <Sheet
      open={open}
      onOpenChange={onOpenChange}
      title={editing ? 'Edit link' : 'New link'}
      description={
        editing ? `Changes take effect on the next request for /${link.slug}.` : undefined
      }
    >
      <form id="link-form" className="flex flex-col gap-5" onSubmit={submit}>
        {failure !== null && Object.keys(failure.errors).length === 0 ? (
          <p className="text-critical text-sm" role="alert">
            {failure.message}
          </p>
        ) : null}

        <Field
          label="Destination"
          hint="Where the short link sends a visitor."
          error={failure?.errors.destination?.[0]}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="destination"
              type="url"
              inputMode="url"
              spellCheck={false}
              aria-describedby={describedBy}
              defaultValue={link?.destination ?? ''}
              required
            />
          )}
        </Field>

        <Field label="Domain" error={failure?.errors.domain?.[0]}>
          {({ id, describedBy }) => (
            <Select
              id={id}
              name="domain"
              aria-describedby={describedBy}
              defaultValue={domains.find((domain) => domain.host === link?.domain)?.id ?? ''}
            >
              <option value="">Primary domain</option>
              {domains.map((domain) => (
                <option key={domain.id} value={domain.id}>
                  {domain.host}
                  {domain.verified ? '' : ' (unverified)'}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field
          label="Custom slug"
          hint="Leave empty to generate one. Generated slugs avoid characters that are easy to misread."
          error={failure?.errors.slug?.[0]}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="slug"
              className="tabular"
              autoCapitalize="none"
              spellCheck={false}
              aria-describedby={describedBy}
              defaultValue={link?.slug ?? ''}
            />
          )}
        </Field>

        <Field
          label="Redirect mode"
          hint="Direct is the fast path. Interstitial holds on a branded page to collect client-side signals."
          error={failure?.errors.redirect_mode?.[0]}
        >
          {({ id, describedBy }) => (
            <Select
              id={id}
              name="redirect_mode"
              aria-describedby={describedBy}
              defaultValue={link?.redirect_mode ?? ''}
            >
              <option value="">Instance default</option>
              <option value="direct">Direct</option>
              <option value="interstitial">Interstitial</option>
            </Select>
          )}
        </Field>

        <Field
          label="Password"
          hint={
            link?.password_protected === true
              ? 'This link is protected. Type a new password to replace it, or leave empty to keep it.'
              : 'Optional. A visitor must enter this before the redirect runs.'
          }
          error={failure?.errors.password?.[0]}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="password"
              type="password"
              autoComplete="off"
              aria-describedby={describedBy}
            />
          )}
        </Field>

        <Field label="Expires" error={failure?.errors.expires_at?.[0]}>
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="expires_at"
              type="datetime-local"
              aria-describedby={describedBy}
              defaultValue={link?.expires_at?.slice(0, 16) ?? ''}
            />
          )}
        </Field>

        <Field
          label="Click limit"
          hint="The link stops resolving once it is reached."
          error={failure?.errors.max_clicks?.[0]}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="max_clicks"
              type="number"
              min={1}
              aria-describedby={describedBy}
              defaultValue={link?.max_clicks ?? ''}
            />
          )}
        </Field>

        <Field
          label="Tags"
          hint="Comma separated."
          error={failure?.errors.tags?.[0] ?? failure?.errors['tags.0']?.[0]}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="tags"
              aria-describedby={describedBy}
              defaultValue={link?.tags.join(', ') ?? ''}
            />
          )}
        </Field>

        <div className="flex items-center gap-3">
          <Button intent="primary" size="md" type="submit" disabled={busy} data-testid="save-link">
            {busy ? 'Saving' : editing ? 'Save changes' : 'Create link'}
          </Button>
          <Button intent="ghost" size="md" type="button" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
        </div>
      </form>
    </Sheet>
  );
}

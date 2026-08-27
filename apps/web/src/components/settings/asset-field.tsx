'use client';

import { useRouter } from 'next/navigation';
import { useRef, useState } from 'react';
import { toast } from 'sonner';

import { Trash } from '@/components/icons';
import { Button } from '@/components/ui/button';

/**
 * One branding asset: upload, preview, clear.
 *
 * The upload is multipart, so it cannot go through the JSON helper and performs
 * the CSRF handshake itself. The API runs a session on every route, and a
 * cookie-authenticated POST without an X-XSRF-TOKEN header is refused with 419 —
 * which passes every server-side test and fails the moment a browser tries it.
 */
export function AssetField({
  kind,
  label,
  hint,
  current,
  accept = 'image/png,image/jpeg,image/webp,image/gif',
}: {
  kind: 'logo' | 'wordmark' | 'favicon';
  label: string;
  hint: string;
  current: string | null;
  accept?: string;
}) {
  const router = useRouter();
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function csrf(): Promise<Record<string, string>> {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });

    const token = document.cookie
      .split('; ')
      .find((entry) => entry.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length);

    return token === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(token) };
  }

  async function upload(file: File) {
    setBusy(true);
    setError(null);

    const body = new FormData();
    body.set('kind', kind);
    body.set('asset', file);

    const response = await fetch('/api/v1/branding/assets', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', ...(await csrf()) },
      body,
    });

    setBusy(false);

    if (!response.ok) {
      const payload = (await response.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
      };

      setError(payload.errors?.asset?.[0] ?? payload.message ?? 'That file was refused.');

      return;
    }

    // The file input keeps the rejected filename otherwise, which reads as
    // though the upload stuck.
    if (input.current !== null) {
      input.current.value = '';
    }

    toast.success(`${label} updated`, { id: `asset-${kind}` });
    router.refresh();
  }

  async function clear() {
    setBusy(true);
    setError(null);

    const response = await fetch(`/api/v1/branding/assets/${kind}`, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', ...(await csrf()) },
    });

    setBusy(false);

    if (!response.ok) {
      setError('That asset could not be removed.');

      return;
    }

    toast.success(`${label} removed`, { id: `asset-${kind}` });
    router.refresh();
  }

  return (
    <div className="flex flex-col gap-2" data-testid={`asset-${kind}`}>
      <span className="text-ink text-sm font-medium">{label}</span>

      <div className="flex flex-wrap items-center gap-3">
        <div className="border-border bg-surface flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-(--radius-token-sm) border">
          {current === null ? (
            <span className="text-ink-subtle text-[10px]">None</span>
          ) : (
            /* eslint-disable-next-line @next/next/no-img-element -- an
               operator-uploaded asset of unknown dimensions, re-encoded and
               size-bounded server-side. */
            <img
              src={current}
              alt={`Current ${label.toLowerCase()}`}
              className="max-h-10 max-w-10"
            />
          )}
        </div>

        <input
          ref={input}
          type="file"
          accept={accept}
          disabled={busy}
          data-testid={`asset-input-${kind}`}
          aria-label={`Upload a ${label.toLowerCase()}`}
          className="text-ink-muted file:border-border file:bg-surface file:text-ink min-w-0 flex-1 text-sm file:mr-3 file:rounded-(--radius-token-sm) file:border file:px-3 file:py-1.5"
          onChange={(event) => {
            const file = event.target.files?.[0];

            if (file !== undefined) {
              void upload(file);
            }
          }}
        />

        {current === null ? null : (
          <Button
            type="button"
            intent="ghost"
            size="sm"
            disabled={busy}
            onClick={() => void clear()}
            data-testid={`asset-clear-${kind}`}
          >
            <Trash size={14} />
            Clear
          </Button>
        )}
      </div>

      <p className="text-ink-muted text-xs">{hint}</p>

      {error === null ? null : (
        <p className="text-critical text-xs" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

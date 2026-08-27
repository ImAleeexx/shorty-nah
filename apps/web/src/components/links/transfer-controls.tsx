'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';

import { ArrowSquareOut, Plus } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { ButtonLink } from '@/components/ui/button-link';
import { Field, Select } from '@/components/ui/field';
import { FormError } from '@/components/ui/form-error';
import { Sheet } from '@/components/ui/sheet';
import { apiRequest, type ApiFailure } from '@/lib/client-api';
import type { DomainRecord } from '@/lib/links';

type ImportState = {
  id: string;
  status: string;
  dry_run: boolean;
  total: number;
  processed: number;
  created: number;
  failed: number;
};

/**
 * Moving links in and out.
 *
 * Import is uploaded with FormData rather than through the JSON helper — a file
 * is not JSON — so the CSRF handshake is performed explicitly here. Skipping it
 * is the failure that passes every server-side test and returns 419 the moment a
 * real browser tries it.
 */
export function TransferControls({ domains }: { domains: DomainRecord[] }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const [batch, setBatch] = useState<ImportState | null>(null);
  const formRef = useRef<HTMLFormElement>(null);

  // Polled only while a batch is unfinished. The interval clears itself, so a
  // finished import leaves nothing running behind the sheet.
  useEffect(() => {
    if (batch === null || batch.status === 'finished' || batch.status === 'failed') {
      return;
    }

    const timer = setInterval(() => {
      void apiRequest<{ import: ImportState }>(`/api/v1/links/imports/${batch.id}`).then(
        (result) => {
          if (result.ok) {
            setBatch(result.data.import);

            if (result.data.import.status === 'finished' && !result.data.import.dry_run) {
              router.refresh();
            }
          }
        },
      );
    }, 1000);

    return () => clearInterval(timer);
  }, [batch, router]);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setBusy(true);
    setFailure(null);
    setBatch(null);

    const body = new FormData(event.currentTarget);

    // The API runs a session on every route, so a cookie-authenticated write
    // without an X-XSRF-TOKEN header is refused with 419.
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });

    const token = document.cookie
      .split('; ')
      .find((entry) => entry.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length);

    const response = await fetch('/api/v1/links/import', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(token === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(token) }),
      },
      body,
    });

    setBusy(false);

    const payload = (await response.json().catch(() => ({}))) as {
      import?: ImportState;
      message?: string;
      errors?: Record<string, string[]>;
    };

    if (!response.ok) {
      setFailure({
        ok: false,
        status: response.status,
        message: payload.message ?? 'The import was refused.',
        errors: payload.errors ?? {},
      });

      return;
    }

    if (payload.import !== undefined) {
      setBatch(payload.import);
      formRef.current?.reset();
    }
  }

  return (
    <>
      <div className="flex items-center gap-2">
        <ButtonLink href="/api/v1/links/export" intent="outline" size="sm" testId="export-links">
          <ArrowSquareOut size={14} />
          Export
        </ButtonLink>

        <Button intent="outline" size="sm" onClick={() => setOpen(true)} data-testid="open-import">
          <Plus size={14} />
          Import
        </Button>
      </div>

      <Sheet
        open={open}
        onOpenChange={(next) => {
          setOpen(next);

          if (!next) {
            setBatch(null);
            setFailure(null);
          }
        }}
        title="Import links"
        description="A CSV with a header row. destination is the only required column; slug, redirect_mode, expires_at, max_clicks and tags are optional."
      >
        <form ref={formRef} className="flex flex-col gap-5" onSubmit={submit}>
          <FormError failure={failure} />

          <Field label="File" error={failure?.errors.file?.[0]}>
            {({ id, describedBy }) => (
              <input
                id={id}
                name="file"
                type="file"
                accept=".csv,text/csv"
                required
                aria-describedby={describedBy}
                className="text-ink-muted file:border-border file:bg-surface file:text-ink w-full text-sm file:mr-3 file:rounded-(--radius-token-sm) file:border file:px-3 file:py-1.5"
                data-testid="import-file"
              />
            )}
          </Field>

          <Field label="Domain" error={failure?.errors.domain?.[0]}>
            {({ id, describedBy }) => (
              <Select
                id={id}
                name="domain"
                aria-describedby={describedBy}
                data-testid="import-domain"
              >
                <option value="">Primary domain</option>
                {domains
                  .filter((domain) => domain.verified)
                  .map((domain) => (
                    <option key={domain.id} value={domain.id}>
                      {domain.host}
                    </option>
                  ))}
              </Select>
            )}
          </Field>

          <label className="text-ink flex items-center gap-2 text-sm">
            <input type="checkbox" name="dry_run" value="1" data-testid="import-dry-run" />
            Rehearse without creating anything
          </label>

          <div className="flex items-center gap-3">
            <Button
              intent="primary"
              size="md"
              type="submit"
              disabled={busy}
              data-testid="start-import"
            >
              {busy ? 'Uploading' : 'Import'}
            </Button>
          </div>
        </form>

        {batch !== null ? (
          <div className="border-border mt-6 border-t pt-4" data-testid="import-progress">
            <p className="text-ink text-sm">
              {batch.status === 'finished'
                ? batch.dry_run
                  ? 'Rehearsed. Nothing was created.'
                  : 'Finished.'
                : 'Working.'}{' '}
              <span className="tabular">
                {batch.processed} of {batch.total}
              </span>
              , <span className="tabular">{batch.created}</span>{' '}
              {batch.dry_run ? 'would be created' : 'created'},{' '}
              <span className="tabular">{batch.failed}</span> refused.
            </p>

            {batch.status === 'finished' ? (
              <div className="mt-3">
                <ButtonLink
                  href={`/api/v1/links/imports/${batch.id}/result`}
                  intent="outline"
                  size="sm"
                  testId="download-result"
                >
                  <ArrowSquareOut size={14} />
                  Download the result
                </ButtonLink>
              </div>
            ) : null}
          </div>
        ) : null}
      </Sheet>
    </>
  );
}

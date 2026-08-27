'use client';

import { useEffect, useState } from 'react';

import { ArrowSquareOut } from '@/components/icons';
import { ButtonLink } from '@/components/ui/button-link';

/**
 * A link's QR code, fetched as an object URL rather than pointed at directly.
 *
 * The endpoint answers with a download disposition, which is what an operator
 * wants from the buttons and exactly what a preview must not do — an <img> src
 * pointing at it would download the file on render.
 */
export function QrPanel({ linkId, slug }: { linkId: string; slug: string }) {
  const [preview, setPreview] = useState<string | null>(null);
  const [fallback, setFallback] = useState(false);

  useEffect(() => {
    let objectUrl: string | null = null;
    let cancelled = false;

    async function load() {
      const response = await fetch(`/api/v1/links/${linkId}/qr?format=svg`, {
        credentials: 'same-origin',
      });

      if (!response.ok || cancelled) {
        return;
      }

      setFallback(response.headers.get('X-Qr-Fallback') === 'ink');

      objectUrl = URL.createObjectURL(await response.blob());
      setPreview(objectUrl);
    }

    void load();

    return () => {
      cancelled = true;

      if (objectUrl !== null) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [linkId]);

  return (
    <div className="flex flex-wrap items-start gap-5" data-testid="qr-panel">
      <div className="border-border rounded-(--radius-token) border p-2">
        {preview === null ? (
          <div className="bg-surface-muted size-32" aria-hidden />
        ) : (
          // eslint-disable-next-line @next/next/no-img-element -- an object URL, not an asset
          <img src={preview} alt={`QR code for /${slug}`} className="size-32" />
        )}
      </div>

      <div className="flex flex-col gap-2">
        <p className="text-ink-muted max-w-64 text-xs">
          {fallback
            ? 'Rendered in ink: the instance accent is too pale for a scanner to read reliably.'
            : 'Rendered in the instance accent.'}
        </p>

        <div className="flex items-center gap-2">
          <ButtonLink href={`/api/v1/links/${linkId}/qr?format=png`} intent="outline" size="sm">
            <ArrowSquareOut size={14} />
            PNG
          </ButtonLink>

          <ButtonLink href={`/api/v1/links/${linkId}/qr?format=svg`} intent="outline" size="sm">
            <ArrowSquareOut size={14} />
            SVG
          </ButtonLink>
        </div>
      </div>
    </div>
  );
}

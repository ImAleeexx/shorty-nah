'use client';

import { ArrowSquareOut } from '@/components/icons';
import { Button } from '@/components/ui/button';

/**
 * The export is a streamed CSV from an authenticated endpoint, so it is reached
 * as an ordinary same-origin navigation rather than fetched and re-assembled in
 * memory: the browser's own download handling is better than anything rebuilt
 * here, and the session cookie travels with it.
 */
export function ExportButton({ linkId }: { linkId: string }) {
  return (
    <Button
      intent="outline"
      size="md"
      onClick={() => window.open(`/api/v1/links/${linkId}/export`, '_self')}
      data-testid="export-clicks"
    >
      <ArrowSquareOut size={15} />
      Export CSV
    </Button>
  );
}

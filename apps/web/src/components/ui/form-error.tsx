import type { ApiFailure } from '@/lib/client-api';

/**
 * The failure a form cannot attach to one of its fields.
 *
 * Rendering only per-field errors loses every refusal that names no field — a
 * 423 asking for the password again, a 419 stale token, a 500. Branding shipped
 * that way, and the effect was a save button that did nothing at all, with no
 * way for an operator to tell a refusal from a bug.
 */
export function FormError({ failure }: { failure: ApiFailure | null }) {
  if (failure === null || Object.keys(failure.errors).length > 0) {
    return null;
  }

  return (
    <p className="text-critical text-sm" role="alert" data-testid="form-error">
      {failure.message}
    </p>
  );
}

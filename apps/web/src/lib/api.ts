import type { PublicConfiguration } from '@/lib/branding';

/**
 * Where the server talks to the API.
 *
 * Inside the container the two are separate services, so a server component
 * cannot use a relative path. A browser always uses the shared origin, which is
 * what keeps session cookies working without CORS.
 */
const INTERNAL_API = process.env.INTERNAL_API_URL ?? 'http://api:8000';

export async function fetchPublicConfiguration(): Promise<PublicConfiguration | null> {
  try {
    const response = await fetch(`${INTERNAL_API}/api/v1/config`, {
      // Branding must be current on every request; a cached copy would keep
      // serving the previous accent after an operator changed it.
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
      return null;
    }

    return (await response.json()) as PublicConfiguration;
  } catch {
    // The interface still renders with defaults if the API is unreachable. A
    // blank page would be a worse answer than an unbranded one.
    return null;
  }
}

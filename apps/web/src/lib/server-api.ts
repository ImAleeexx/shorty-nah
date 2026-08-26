import { cookies } from 'next/headers';

/**
 * The server's half of the data layer.
 *
 * A page's initial data is fetched here and rendered on the server, which keeps
 * the browser bundle to what interaction actually needs. The viewer's session
 * cookie is forwarded explicitly: inside the container the API is a separate
 * service, so nothing is inherited from the incoming request automatically.
 */
const INTERNAL_API = process.env.INTERNAL_API_URL ?? 'http://api:8000';

export type ApiResult<T> = { ok: true; data: T } | { ok: false; status: number };

export async function apiGet<T>(path: string): Promise<ApiResult<T>> {
  const jar = await cookies();
  const header = jar
    .getAll()
    .map((cookie) => `${cookie.name}=${cookie.value}`)
    .join('; ');

  let response: Response;

  try {
    response = await fetch(`${INTERNAL_API}${path}`, {
      // Operator data is per-viewer and changes constantly; a cached copy would
      // show one operator another's list.
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        ...(header === '' ? {} : { Cookie: header }),
      },
    });
  } catch {
    return { ok: false, status: 0 };
  }

  if (!response.ok) {
    return { ok: false, status: response.status };
  }

  return { ok: true, data: (await response.json()) as T };
}

'use client';

/**
 * The browser's half of the data layer.
 *
 * Writes go straight to the API from the component that owns them, then the
 * caller asks the router to re-render so the server-fetched view catches up.
 *
 * The session stack protects cookie-authenticated requests with a CSRF token, so
 * every unsafe method carries one. This is the part that cannot be discovered in
 * Pest: CSRF is inert under the testing environment and only a real browser
 * shows the 419.
 */
const XSRF_COOKIE = 'XSRF-TOKEN';

export type ApiFailure = {
  ok: false;
  status: number;
  message: string;
  errors: Record<string, string[]>;
};

export type ApiResult<T> = { ok: true; data: T } | ApiFailure;

function readCookie(name: string): string | null {
  const prefix = `${name}=`;

  for (const entry of document.cookie.split('; ')) {
    if (entry.startsWith(prefix)) {
      return decodeURIComponent(entry.slice(prefix.length));
    }
  }

  return null;
}

async function csrfToken(): Promise<string | null> {
  const existing = readCookie(XSRF_COOKIE);

  if (existing !== null) {
    return existing;
  }

  try {
    await fetch('/sanctum/csrf-cookie', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
  } catch {
    return null;
  }

  return readCookie(XSRF_COOKIE);
}

type LaravelError = { message?: unknown; errors?: unknown };

function readErrors(body: LaravelError): Record<string, string[]> {
  if (typeof body.errors !== 'object' || body.errors === null) {
    return {};
  }

  const errors: Record<string, string[]> = {};

  for (const [field, messages] of Object.entries(body.errors as Record<string, unknown>)) {
    if (Array.isArray(messages)) {
      errors[field] = messages.filter((message): message is string => typeof message === 'string');
    }
  }

  return errors;
}

export async function apiRequest<T>(
  path: string,
  options: { method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'; body?: unknown } = {},
): Promise<ApiResult<T>> {
  const method = options.method ?? 'GET';

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  if (method !== 'GET') {
    const csrf = await csrfToken();

    if (csrf !== null) {
      headers['X-XSRF-TOKEN'] = csrf;
    }
  }

  let response: Response;

  try {
    response = await fetch(path, {
      method,
      credentials: 'same-origin',
      headers,
      body: method === 'GET' ? undefined : JSON.stringify(options.body ?? {}),
    });
  } catch {
    return {
      ok: false,
      status: 0,
      message: 'The instance could not be reached.',
      errors: {},
    };
  }

  if (response.status === 204) {
    return { ok: true, data: undefined as T };
  }

  if (response.ok) {
    return { ok: true, data: (await response.json()) as T };
  }

  let body: LaravelError = {};

  try {
    body = (await response.json()) as LaravelError;
  } catch {
    // A gateway can answer with something that is not JSON; the status still is.
  }

  return {
    ok: false,
    status: response.status,
    message:
      typeof body.message === 'string' && body.message !== ''
        ? body.message
        : 'That could not be completed.',
    errors: readErrors(body),
  };
}

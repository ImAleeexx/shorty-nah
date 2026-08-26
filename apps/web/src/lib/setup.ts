/**
 * The browser's half of the first-boot wizard.
 *
 * Every call is same-origin, so the setup token travels in a header rather than
 * a query string: a URL ends up in logs and history, and this one credential is
 * the only thing standing between a freshly deployed instance and whoever finds
 * it first.
 */
export const SETUP_TOKEN_HEADER = 'X-Setup-Token';

/** Where the token lives between reloads. Deliberately not localStorage: it is
 * spent once, and should not outlive the tab that used it. */
export const SETUP_TOKEN_STORAGE_KEY = 'shortynah:setup-token';

export const SETUP_STEPS = [
  'connectivity',
  'administrator',
  'instance',
  'branding',
  'analytics',
  'registration',
  'mail',
] as const;

export type SetupStep = (typeof SETUP_STEPS)[number];

export type DependencyStatus = {
  name: string;
  healthy: boolean;
  reason: string | null;
};

export type SetupStepState = {
  step: SetupStep;
  complete: boolean;
  skippable: boolean;
};

export type SetupState = {
  installed: boolean;
  steps: SetupStepState[];
  next: SetupStep | null;
  values: {
    instance_name: string | null;
    domain: string | null;
    accent: string | null;
    radius: number | null;
    typeface: string | null;
    retention_days: number | null;
    bot_filtering: boolean | null;
    registration_mode: string | null;
    mail_host: string | null;
    mail_port: number | null;
    mail_username: string | null;
    mail_from_address: string | null;
  };
};

export type ConnectivityResult = {
  healthy: boolean;
  dependencies: DependencyStatus[];
  next: SetupStep | null;
};

export type StepAccepted = { next: SetupStep | null };

export type CompletedInstall = {
  installed: true;
  user: { id: string; name: string; email: string; role: string };
};

export type SetupFailure = {
  ok: false;
  status: number;
  message: string;
  errors: Record<string, string[]>;
};

export type SetupResult<T> = { ok: true; data: T } | SetupFailure;

type LaravelError = { message?: unknown; errors?: unknown };

const XSRF_COOKIE = 'XSRF-TOKEN';

function readCookie(name: string): string | null {
  const prefix = `${name}=`;

  for (const entry of document.cookie.split('; ')) {
    if (entry.startsWith(prefix)) {
      return decodeURIComponent(entry.slice(prefix.length));
    }
  }

  return null;
}

/**
 * The session stack protects cookie-authenticated requests with a CSRF token, so
 * the wizard has to hold one like any other first-party caller. Sanctum issues it
 * as a cookie; it travels back in a header, which is the part a cross-site form
 * cannot forge.
 */
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

async function request<T>(
  path: string,
  options: { token: string; method?: 'GET' | 'POST'; body?: unknown },
): Promise<SetupResult<T>> {
  const method = options.method ?? 'POST';

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    [SETUP_TOKEN_HEADER]: options.token,
  };

  if (method !== 'GET') {
    const csrf = await csrfToken();

    if (csrf !== null) {
      headers['X-XSRF-TOKEN'] = csrf;
    }
  }

  let response: Response;

  try {
    response = await fetch(`/api/v1/setup/${path}`, {
      method,
      // The wizard's last act signs the owner in, so the session cookie it sets
      // has to be accepted and kept.
      credentials: 'same-origin',
      headers,
      body: method === 'GET' ? undefined : JSON.stringify(options.body ?? {}),
    });
  } catch {
    return {
      ok: false,
      status: 0,
      message: 'The instance could not be reached. Check that every service is running.',
      errors: {},
    };
  }

  if (response.ok) {
    return { ok: true, data: (await response.json()) as T };
  }

  let body: LaravelError = {};

  try {
    body = (await response.json()) as LaravelError;
  } catch {
    // A gateway can answer with something that is not JSON; the status is still
    // the actionable part.
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

export function verifyToken(token: string): Promise<SetupResult<{ valid: true }>> {
  return request('token', { token });
}

export function fetchState(token: string): Promise<SetupResult<SetupState>> {
  return request('state', { token, method: 'GET' });
}

export function checkConnectivity(token: string): Promise<SetupResult<ConnectivityResult>> {
  return request('connectivity', { token });
}

export function submitStep(
  step: Exclude<SetupStep, 'connectivity'>,
  token: string,
  body: Record<string, unknown>,
): Promise<SetupResult<StepAccepted>> {
  return request(step, { token, body });
}

export function completeInstallation(token: string): Promise<SetupResult<CompletedInstall>> {
  return request('complete', { token });
}

export function readStoredToken(): string {
  if (typeof window === 'undefined') {
    return '';
  }

  try {
    return window.sessionStorage.getItem(SETUP_TOKEN_STORAGE_KEY) ?? '';
  } catch {
    // A browser with storage disabled still gets a working wizard; it just
    // cannot resume after a reload.
    return '';
  }
}

export function storeToken(token: string): void {
  try {
    window.sessionStorage.setItem(SETUP_TOKEN_STORAGE_KEY, token);
  } catch {
    // As above: storage is a convenience here, never a requirement.
  }
}

export function forgetToken(): void {
  try {
    window.sessionStorage.removeItem(SETUP_TOKEN_STORAGE_KEY);
  } catch {
    // Nothing to clean up if it was never stored.
  }
}

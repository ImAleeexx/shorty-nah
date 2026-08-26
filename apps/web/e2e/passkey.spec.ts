import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080';

// Its own account: this suite enrols a second factor, and every other spec
// signs in as the shared operator while these run in parallel.
const OPERATOR = { email: 'passkey@example.test', password: 'a second quiet lantern drifts' };

/**
 * The WebAuthn ceremony cannot be faked at the API boundary — it is signature
 * verification against a real credential. Chrome's virtual authenticator does
 * the real thing without hardware, which is the only honest way to test it.
 */
const AUTHENTICATOR = {
  protocol: 'ctap2' as const,
  transport: 'internal' as const,
  hasResidentKey: true,
  hasUserVerification: true,
  isUserVerified: true,
  automaticPresenceSimulation: true,
};

type Outcome = {
  registered: { status: number; name?: string; addedAt?: string | null };
  passwordAlone: { status: number; twoFactorRequired?: boolean };
  authenticated: { status: number; email?: string };
  whoami: { status: number; email?: string };
  listed: { status: number; type?: string; addedAt?: string | null };
};

test.describe('passkeys', () => {
  test('registers a passkey and then authenticates with it', async ({ page }) => {
    const client = await page.context().newCDPSession(page);
    await client.send('WebAuthn.enable');
    await client.send('WebAuthn.addVirtualAuthenticator', { options: AUTHENTICATOR });

    await page.goto(`${APP}/sign-in`);
    await page.getByLabel('Email').fill(OPERATOR.email);
    await page.getByLabel('Password').fill(OPERATOR.password);
    await page.getByTestId('sign-in').click();
    await expect(page).toHaveURL(`${APP}/`);

    const outcome: Outcome = await page.evaluate(async (operator) => {
      const toBuffer = (value: string): ArrayBuffer => {
        const padded = value.replace(/-/g, '+').replace(/_/g, '/');
        const binary = atob(padded.padEnd(Math.ceil(padded.length / 4) * 4, '='));
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
      };

      const toBase64Url = (buffer: ArrayBuffer): string => {
        let binary = '';
        for (const byte of new Uint8Array(buffer)) binary += String.fromCharCode(byte);
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
      };

      const readCookie = (): string | null => {
        for (const entry of document.cookie.split('; ')) {
          if (entry.startsWith('XSRF-TOKEN=')) return decodeURIComponent(entry.slice(11));
        }
        return null;
      };

      const csrf = async (): Promise<string | null> => {
        if (readCookie() === null) {
          await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
        }
        return readCookie();
      };

      const send = async (method: string, path: string, body?: unknown) => {
        const token = await csrf();
        const response = await fetch(path, {
          method,
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
          },
          body: method === 'GET' ? undefined : JSON.stringify(body ?? {}),
        });
        return {
          status: response.status,
          body: (await response.json().catch(() => null)) as Record<string, never> | null,
        };
      };

      // --- Register a passkey ---
      const options = await send('POST', '/api/v1/auth/two-factor/passkey/options');
      const creation = options.body as unknown as PublicKeyCredentialCreationOptionsJSON;

      const created = (await navigator.credentials.create({
        publicKey: {
          ...creation,
          challenge: toBuffer(creation.challenge),
          user: { ...creation.user, id: toBuffer(creation.user.id) },
          excludeCredentials: (creation.excludeCredentials ?? []).map((entry) => ({
            ...entry,
            id: toBuffer(entry.id),
          })),
        } as unknown as PublicKeyCredentialCreationOptions,
      })) as PublicKeyCredential;

      const attestation = created.response as AuthenticatorAttestationResponse;

      const registered = await send('POST', '/api/v1/auth/two-factor/passkey', {
        name: 'Virtual key',
        credential: JSON.stringify({
          id: created.id,
          rawId: toBase64Url(created.rawId),
          type: created.type,
          response: {
            clientDataJSON: toBase64Url(attestation.clientDataJSON),
            attestationObject: toBase64Url(attestation.attestationObject),
          },
          clientExtensionResults: created.getClientExtensionResults(),
        }),
      });

      // --- A correct password now grants nothing ---
      await send('DELETE', '/api/v1/auth/session');

      const passwordAlone = await send('POST', '/api/v1/auth/session', {
        email: operator.email,
        password: operator.password,
      });

      // --- Satisfy the challenge with the passkey ---
      const requestOptions = await send('POST', '/api/v1/auth/two-factor/challenge/passkey');
      const request = requestOptions.body as unknown as PublicKeyCredentialRequestOptionsJSON;

      const assertion = (await navigator.credentials.get({
        publicKey: {
          ...request,
          challenge: toBuffer(request.challenge),
          allowCredentials: (request.allowCredentials ?? []).map((entry) => ({
            ...entry,
            id: toBuffer(entry.id),
          })),
        } as unknown as PublicKeyCredentialRequestOptions,
      })) as PublicKeyCredential;

      const assertionResponse = assertion.response as AuthenticatorAssertionResponse;

      const authenticated = await send('POST', '/api/v1/auth/two-factor/challenge', {
        credential: JSON.stringify({
          id: assertion.id,
          rawId: toBase64Url(assertion.rawId),
          type: assertion.type,
          response: {
            clientDataJSON: toBase64Url(assertionResponse.clientDataJSON),
            authenticatorData: toBase64Url(assertionResponse.authenticatorData),
            signature: toBase64Url(assertionResponse.signature),
            userHandle:
              assertionResponse.userHandle === null
                ? null
                : toBase64Url(assertionResponse.userHandle),
          },
          clientExtensionResults: assertion.getClientExtensionResults(),
        }),
      });

      const whoami = await send('GET', '/api/v1/auth/user');
      const listing = await send('GET', '/api/v1/auth/two-factor');

      const credentials = (listing.body?.credentials ?? []) as unknown as {
        type: string;
        added_at: string | null;
      }[];

      return {
        registered: {
          status: registered.status,
          name: registered.body?.name as string | undefined,
          addedAt: (registered.body?.added_at ?? null) as string | null,
        },
        passwordAlone: {
          status: passwordAlone.status,
          twoFactorRequired: passwordAlone.body?.two_factor_required as boolean | undefined,
        },
        authenticated: {
          status: authenticated.status,
          email: (authenticated.body?.user as { email?: string } | undefined)?.email,
        },
        whoami: {
          status: whoami.status,
          email: (whoami.body?.user as { email?: string } | undefined)?.email,
        },
        listed: {
          status: listing.status,
          type: credentials[0]?.type,
          addedAt: credentials[0]?.added_at ?? null,
        },
      };
    }, OPERATOR);

    expect(outcome.registered.status).toBe(201);
    expect(outcome.registered.name).toBe('Virtual key');

    // A correct password alone establishes nothing once a factor is enrolled.
    expect(outcome.passwordAlone.status).toBe(202);
    expect(outcome.passwordAlone.twoFactorRequired).toBe(true);

    // The credential authenticates, which is signature verification against a
    // real key rather than a shape check.
    expect(outcome.authenticated.status).toBe(200);
    expect(outcome.authenticated.email).toBe(OPERATOR.email);

    expect(outcome.whoami.status).toBe(200);
    expect(outcome.whoami.email).toBe(OPERATOR.email);

    // Listed with the date it was added, which is what makes an unrecognised
    // factor noticeable.
    expect(outcome.listed.type).toBe('webauthn');
    expect(outcome.listed.addedAt).toBeTruthy();
  });
});

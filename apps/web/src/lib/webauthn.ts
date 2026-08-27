'use client';

/**
 * The browser half of a WebAuthn ceremony.
 *
 * The server speaks base64url and `navigator.credentials` speaks ArrayBuffer, so
 * every identifier and challenge is converted on the way in and back on the way
 * out. Getting one of these backwards produces a credential the server cannot
 * verify, with no useful error at either end — which is why it lives in one
 * place rather than inline in a component.
 */

function toBuffer(value: string): ArrayBuffer {
  const normalised = value.replace(/-/g, '+').replace(/_/g, '/');
  const binary = atob(normalised.padEnd(Math.ceil(normalised.length / 4) * 4, '='));
  const bytes = new Uint8Array(binary.length);

  for (let at = 0; at < binary.length; at++) {
    bytes[at] = binary.charCodeAt(at);
  }

  return bytes.buffer;
}

function toBase64Url(value: ArrayBuffer): string {
  const bytes = new Uint8Array(value);
  let binary = '';

  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function passkeysAvailable(): boolean {
  return typeof window !== 'undefined' && typeof window.PublicKeyCredential === 'function';
}

type CreationOptions = {
  challenge: string;
  user: { id: string; name: string; displayName: string };
  excludeCredentials?: { id: string; type: string }[];
};

/**
 * Runs the registration ceremony and returns what the server needs to store,
 * already serialised the way it expects.
 */
export async function createPasskey(options: CreationOptions): Promise<string> {
  const created = (await navigator.credentials.create({
    publicKey: {
      ...options,
      challenge: toBuffer(options.challenge),
      user: { ...options.user, id: toBuffer(options.user.id) },
      excludeCredentials: (options.excludeCredentials ?? []).map((entry) => ({
        ...entry,
        id: toBuffer(entry.id),
      })),
    } as unknown as PublicKeyCredentialCreationOptions,
  })) as PublicKeyCredential | null;

  if (created === null) {
    throw new Error('No credential was created.');
  }

  const attestation = created.response as AuthenticatorAttestationResponse;

  return JSON.stringify({
    id: created.id,
    rawId: toBase64Url(created.rawId),
    type: created.type,
    response: {
      clientDataJSON: toBase64Url(attestation.clientDataJSON),
      attestationObject: toBase64Url(attestation.attestationObject),
    },
    clientExtensionResults: created.getClientExtensionResults(),
  });
}

type RequestOptions = {
  challenge: string;
  allowCredentials?: { id: string; type: string }[];
};

/**
 * Runs the authentication ceremony and returns what the server needs to verify,
 * serialised the way it expects.
 *
 * Deliberately not named `usePasskey`: a `use` prefix makes the lint rules treat
 * a plain async function as a React hook and refuse every call site.
 */
export async function authenticateWithPasskey(options: RequestOptions): Promise<string> {
  const asserted = (await navigator.credentials.get({
    publicKey: {
      ...options,
      challenge: toBuffer(options.challenge),
      allowCredentials: (options.allowCredentials ?? []).map((entry) => ({
        ...entry,
        id: toBuffer(entry.id),
      })),
    } as unknown as PublicKeyCredentialRequestOptions,
  })) as PublicKeyCredential | null;

  if (asserted === null) {
    throw new Error('No credential was returned.');
  }

  const assertion = asserted.response as AuthenticatorAssertionResponse;

  return JSON.stringify({
    id: asserted.id,
    rawId: toBase64Url(asserted.rawId),
    type: asserted.type,
    response: {
      clientDataJSON: toBase64Url(assertion.clientDataJSON),
      authenticatorData: toBase64Url(assertion.authenticatorData),
      signature: toBase64Url(assertion.signature),
      userHandle: assertion.userHandle === null ? null : toBase64Url(assertion.userHandle),
    },
    clientExtensionResults: asserted.getClientExtensionResults(),
  });
}

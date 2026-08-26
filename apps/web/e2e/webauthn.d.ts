/**
 * The JSON shapes the API returns for a WebAuthn ceremony. The DOM types
 * describe the ArrayBuffer form the browser consumes; these describe what
 * arrives over the wire, before it is converted.
 */
type PublicKeyCredentialCreationOptionsJSON = {
  challenge: string;
  user: { id: string; name: string; displayName: string };
  excludeCredentials?: { id: string; type: string }[];
};

type PublicKeyCredentialRequestOptionsJSON = {
  challenge: string;
  allowCredentials?: { id: string; type: string }[];
};

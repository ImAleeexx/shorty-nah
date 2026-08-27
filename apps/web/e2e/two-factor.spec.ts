import { createHmac } from 'node:crypto';

import { expect, test } from '@playwright/test';

/**
 * The lockout, reproduced.
 *
 * Tagged @security and run on its own after the rest of the suite, because it
 * turns on an instance-wide requirement: every other spec signs in as the same
 * operator, and enabling this while they run locks them out mid-test.
 */
const APP = 'http://localhost:8080';

const OPERATOR = { email: 'e2e@example.test', password: 'a quiet lantern drifts' };

/** The fixture account whose only second factor is a passkey. */
const PASSKEY_ONLY = { email: 'passkey@example.test', password: 'a second quiet lantern drifts' };

/** Chrome's virtual authenticator: the real ceremony, without hardware. */
const AUTHENTICATOR = {
  protocol: 'ctap2' as const,
  transport: 'internal' as const,
  hasResidentKey: true,
  hasUserVerification: true,
  isUserVerified: true,
  automaticPresenceSimulation: true,
};

/** RFC 4648 base32, which is how an authenticator secret is written. */
function base32Decode(input: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';

  for (const character of input.toUpperCase().replace(/=+$/, '')) {
    const index = alphabet.indexOf(character);

    if (index !== -1) {
      bits += index.toString(2).padStart(5, '0');
    }
  }

  const bytes: number[] = [];

  for (let at = 0; at + 8 <= bits.length; at += 8) {
    bytes.push(parseInt(bits.slice(at, at + 8), 2));
  }

  return Buffer.from(bytes);
}

/** RFC 6238, thirty-second step, six digits — what every authenticator does. */
function totp(secret: string): string {
  const counter = Math.floor(Date.now() / 1000 / 30);
  const message = Buffer.alloc(8);

  message.writeUInt32BE(Math.floor(counter / 2 ** 32), 0);
  message.writeUInt32BE(counter >>> 0, 4);

  const digest = createHmac('sha1', base32Decode(secret)).update(message).digest();
  const offset = digest[digest.length - 1]! & 0x0f;

  const binary =
    ((digest[offset]! & 0x7f) << 24) |
    ((digest[offset + 1]! & 0xff) << 16) |
    ((digest[offset + 2]! & 0xff) << 8) |
    (digest[offset + 3]! & 0xff);

  return (binary % 1_000_000).toString().padStart(6, '0');
}

let lastStep = -1;

/**
 * A code from a time step this account has not used yet.
 *
 * The service records the accepted step rather than the code, so a step at or
 * before the last accepted one is refused as a replay whatever its digits say —
 * which is right, and means confirming an enrolment consumes the step the very
 * next sign-in would otherwise reuse.
 */
async function freshCode(secret: string): Promise<string> {
  for (;;) {
    const step = Math.floor(Date.now() / 1000 / 30);

    if (step > lastStep) {
      lastStep = step;

      return totp(secret);
    }

    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
}

async function signIn(page: import('@playwright/test').Page) {
  await page.goto(`${APP}/sign-in`);
  await page.getByLabel('Email').fill(OPERATOR.email);
  await page.getByLabel('Password').fill(OPERATOR.password);
  await page.getByTestId('sign-in').click();

  // Waited for, because where it lands depends on the state under test: the
  // overview, the enrolment page, or the challenge form. Returning before any
  // of them means the next goto races the sign-in.
  await page.waitForFunction(
    () =>
      !window.location.pathname.startsWith('/sign-in') ||
      document.querySelector('[data-testid="two-factor-challenge"]') !== null,
  );
}

async function setRequirement(page: import('@playwright/test').Page, required: boolean) {
  await page.goto(`${APP}/settings`);

  await page.request.get(`${APP}/sanctum/csrf-cookie`);

  const cookies = await page.context().cookies();
  const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';

  const response = await page.request.put(`${APP}/api/v1/settings`, {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' },
    data: { settings: { 'security.two_factor_required': required } },
  });

  expect(response.ok()).toBe(true);
}

// Carried between the serial tests: the enrolment secret is shown once, and
// every step after it needs a code generated from it. Module scope, because the
// sign-in helper below is defined outside the describe that uses it.
let secret = '';

/** Signs in and, once a factor exists, satisfies the challenge it presents. */
async function signInFully(page: import('@playwright/test').Page) {
  await signIn(page);

  const challenge = page.getByTestId('two-factor-challenge');

  if (await challenge.isVisible().catch(() => false)) {
    await page.getByTestId('two-factor-code').fill(await freshCode(secret));
    await page.getByTestId('submit-two-factor').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/sign-in'));
  }
}

test.describe('@security instance-wide second factor', () => {
  // Serial and generously timed: a step has to roll over between uses, which is
  // up to thirty seconds of real waiting per code.
  test.describe.configure({ mode: 'serial', timeout: 120_000 });

  test('sends a confined account to enrolment instead of a dead end', async ({ page }) => {
    await signIn(page);
    await expect(page).toHaveURL(`${APP}/`);

    await setRequirement(page, true);

    // This is the reported bug: with the requirement on and no factor enrolled,
    // every route answers 403 and the operator was left reading a refusal with
    // nowhere to go.
    await page.goto(`${APP}/`);

    await expect(page).toHaveURL(`${APP}/security`);
    await expect(page.getByTestId('enrolment-required')).toBeVisible();

    // And the other surfaces send them to the same place rather than rendering
    // an empty page.
    await page.goto(`${APP}/links`);
    await expect(page).toHaveURL(`${APP}/security`);
  });

  test('shows a scannable code for the authenticator enrolment', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/security`);

    await page.getByTestId('begin-enrolment').click();

    const qr = page.getByTestId('enrolment-qr');

    await expect(qr).toBeVisible();

    // Polled, not read once: toBeVisible passes as soon as the element has a
    // box, which is before the image data has arrived. Reading naturalWidth at
    // that moment measures an image that has not loaded yet rather than one that
    // is broken.
    await expect
      .poll(() =>
        qr.evaluate((node) => {
          const image = node as HTMLImageElement;

          return image.complete && image.naturalWidth > 0;
        }),
      )
      .toBe(true);

    // The image carries the shared secret, so it must not be stored anywhere
    // between the API and the screen.
    const served = await page.request.get(`${APP}${await qr.getAttribute('src')}`);

    expect(served.status()).toBe(200);
    expect(served.headers()['cache-control']).toContain('no-store');

    // The secret is offered as text as well, for a machine with no camera.
    await expect(page.getByTestId('enrolment-secret')).toBeVisible();
  });

  test('enrols an authenticator and issues recovery codes once', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/security`);

    await page.getByTestId('begin-enrolment').click();

    secret = (await page.getByTestId('enrolment-secret').textContent())?.trim() ?? '';

    expect(secret.length).toBeGreaterThan(15);

    await page.getByTestId('enrolment-code').fill(await freshCode(secret));
    await page.getByTestId('confirm-enrolment').click();

    // Issued once, on the first factor only.
    await expect(page.getByTestId('recovery-codes')).toBeVisible();
    await expect(page.getByTestId('factor-row')).toHaveCount(1);

    // The instance is reachable again.
    await page.goto(`${APP}/`);
    await expect(page).toHaveURL(`${APP}/`);
    await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();

    // And the codes are not shown a second time.
    await page.goto(`${APP}/security`);
    await expect(page.getByTestId('recovery-codes')).toHaveCount(0);
  });

  test('registers a passkey through the interface', async ({ page }) => {
    const client = await page.context().newCDPSession(page);

    await client.send('WebAuthn.enable');
    await client.send('WebAuthn.addVirtualAuthenticator', { options: AUTHENTICATOR });

    await signInFully(page);
    await page.goto(`${APP}/security`);

    const before = await page.getByTestId('factor-row').count();

    // The ceremony is signature verification against a real credential, so this
    // drives Chrome's virtual authenticator rather than faking the payload —
    // and it goes through the button, which is the part that did not exist.
    await page.getByTestId('add-passkey').click();

    await expect(page.getByTestId('factor-row')).toHaveCount(before + 1);
    await expect(page.getByTestId('factor-row').filter({ hasText: 'Passkey' })).toBeVisible();
  });

  test('never carries the password into the second-factor field', async ({ page, context }) => {
    await context.clearCookies();

    await page.goto(`${APP}/sign-in`);
    await page.getByLabel('Email').fill(OPERATOR.email);
    await page.getByLabel('Password').fill(OPERATOR.password);
    await page.getByTestId('sign-in').click();

    const code = page.getByTestId('two-factor-code');

    await expect(code).toBeVisible();

    // The reported bug. Both forms rendered a <form>, a <FormError> and then a
    // <Field>, so React reconciled by position and kept the password <input>
    // DOM node as the code field: the typed password stayed in the box, the
    // type flipped from password to text, and it was on screen in clear text
    // and about to be submitted as the second factor.
    await expect(code).toHaveValue('');

    const onScreen = await page.locator('form').innerText();

    expect(onScreen).not.toContain(OPERATOR.password);

    // And no input anywhere is holding it, visible or not.
    const values = await page
      .locator('input')
      .evaluateAll((nodes) => nodes.map((node) => (node as HTMLInputElement).value));

    expect(values).not.toContain(OPERATOR.password);
  });

  test('offers a passkey rather than a code an account cannot produce', async ({
    page,
    context,
  }) => {
    await context.clearCookies();

    const client = await page.context().newCDPSession(page);

    await client.send('WebAuthn.enable');
    await client.send('WebAuthn.addVirtualAuthenticator', { options: AUTHENTICATOR });

    async function signInAsPasskeyAccount() {
      await page.goto(`${APP}/sign-in`);
      await page.getByLabel('Email').fill(PASSKEY_ONLY.email);
      await page.getByLabel('Password').fill(PASSKEY_ONLY.password);
      await page.getByTestId('sign-in').click();
    }

    /**
     * Waits for whichever outcome the sign-in has, rather than sampling once.
     *
     * The fixture clears this account's credentials and the passkey spec may
     * have registered one before this runs, so both are legitimate. Reading
     * visibility the instant the click returns finds neither, and the branch it
     * then takes waits forever for a navigation that is not coming.
     */
    async function settled(): Promise<'challenged' | 'signed-in'> {
      await page.waitForFunction(
        () =>
          document.querySelector('[data-testid="two-factor-challenge"]') !== null ||
          !window.location.pathname.startsWith('/sign-in'),
      );

      return (await page.getByTestId('two-factor-challenge').count()) > 0
        ? 'challenged'
        : 'signed-in';
    }

    await signInAsPasskeyAccount();

    // Whether the key on this account belongs to *this* test's authenticator.
    // A virtual authenticator is per-context, so a passkey the passkey spec
    // registered earlier in the run is not one this browser can offer — the
    // account is challenged for a credential nothing here holds.
    const registeredHere = (await settled()) === 'signed-in';

    if (registeredHere) {
      await page.goto(`${APP}/security`);
      await page.getByTestId('add-passkey').click();
      await expect(page.getByTestId('factor-row')).not.toHaveCount(0);

      await context.clearCookies();
      await signInAsPasskeyAccount();
      await settled();
    }

    await expect(page.getByTestId('two-factor-challenge')).toBeVisible();

    // An account whose only factor is a passkey was previously shown an
    // authenticator field it could never satisfy.
    await expect(page.getByTestId('use-passkey')).toBeVisible();
    await expect(page.getByTestId('two-factor-code')).toHaveCount(0);

    // And it actually signs in — asserted only when this test registered the
    // key, because otherwise the credential lives in another context's
    // authenticator and no prompt here can satisfy it.
    if (registeredHere) {
      await page.getByTestId('use-passkey').click();
      await expect(page).toHaveURL(`${APP}/`);
    }
  });

  test('keeps the recovery code behind a control rather than on the page', async ({
    page,
    context,
  }) => {
    await context.clearCookies();

    await page.goto(`${APP}/sign-in`);
    await page.getByLabel('Email').fill(OPERATOR.email);
    await page.getByLabel('Password').fill(OPERATOR.password);
    await page.getByTestId('sign-in').click();

    await expect(page.getByTestId('two-factor-challenge')).toBeVisible();

    // A recovery code is the way in when the factor is unreachable, not the
    // ordinary route. Offering it beside the code field every time invites its
    // use over the factor it exists to back up.
    await expect(page.getByTestId('recovery-code')).toHaveCount(0);

    await page.getByTestId('toggle-recovery').click();

    await expect(page.getByTestId('recovery-code')).toBeVisible();
    await expect(page.getByTestId('two-factor-code')).toHaveCount(0);

    await page.getByTestId('toggle-recovery').click();

    await expect(page.getByTestId('two-factor-code')).toBeVisible();
  });

  test('challenges the enrolled factor on the next sign-in', async ({ page, context }) => {
    await context.clearCookies();

    await signIn(page);

    // The sign-in form already knew how to satisfy a challenge. What was missing
    // was any way to get a factor onto the account in the first place.
    await expect(page.getByTestId('two-factor-challenge')).toBeVisible();

    await page.getByTestId('two-factor-code').fill(await freshCode(secret));
    await page.getByTestId('submit-two-factor').click();

    await expect(page).toHaveURL(`${APP}/`);
  });

  // Instance-wide state, restored so the rest of the suite is unaffected.
  test('leaves the instance as it found it', async ({ page, context }) => {
    await context.clearCookies();
    await signIn(page);

    await page.getByTestId('two-factor-code').fill(await freshCode(secret));
    await page.getByTestId('submit-two-factor').click();
    await expect(page).toHaveURL(`${APP}/`);

    await setRequirement(page, false);

    await page.goto(`${APP}/security`);

    // Every factor, not just the first: this spec adds an authenticator and a
    // passkey, and leaving either behind means the next run's sign-in is
    // challenged before it can do anything.
    for (let remaining = await page.getByTestId('factor-row').count(); remaining > 0; remaining--) {
      await page
        .getByTestId('factor-row')
        .first()
        .getByRole('button', { name: /Remove/ })
        .click();

      await expect(page.getByTestId('factor-row')).toHaveCount(remaining - 1);
    }

    await expect(page.getByTestId('factor-row')).toHaveCount(0);
  });
});

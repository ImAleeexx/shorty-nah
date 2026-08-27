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

test.describe('@security instance-wide second factor', () => {
  // Serial and generously timed: a step has to roll over between uses, which is
  // up to thirty seconds of real waiting per code.
  test.describe.configure({ mode: 'serial', timeout: 120_000 });

  // Carried between the serial tests: the enrolment secret is shown once, and
  // every step after it needs a code generated from it.
  let secret = '';

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

    const factor = page.getByTestId('factor-row').first();

    await factor.getByRole('button', { name: /Remove/ }).click();

    await expect(page.getByTestId('factor-row')).toHaveCount(0);
  });
});

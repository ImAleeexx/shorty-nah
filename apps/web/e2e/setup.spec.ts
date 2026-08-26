import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080';

/**
 * Read from the host-mounted file rather than the container log, because that
 * file is the recovery path the design promises an operator. If this read stops
 * working, the promise has stopped being true.
 */
function setupToken(): string {
  const path = join(process.cwd(), '..', '..', 'run', 'setup-token');

  return readFileSync(path, 'utf8').trim();
}

const OWNER = {
  name: 'Alex Owner',
  email: 'owner@example.test',
  password: 'a-long-enough-passphrase-42',
};

// One instance, walked once, in order. Every step depends on the one before it.
test.describe.configure({ mode: 'serial' });

test.describe('first boot', () => {
  test('sends an uninstalled instance to setup', async ({ page }) => {
    await page.goto(`${APP}/`);

    await expect(page).toHaveURL(/\/setup$/);
    await expect(page.getByTestId('setup-gate')).toBeVisible();
  });

  test('accepts nothing before the token is presented', async ({ request }) => {
    // The CSRF handshake first, so what this asserts is the setup gate refusing
    // rather than the session stack refusing on its behalf.
    await request.get(`${APP}/sanctum/csrf-cookie`);

    const { cookies } = await request.storageState();
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';

    expect(xsrf).not.toBe('');

    const response = await request.post(`${APP}/api/v1/setup/administrator`, {
      data: OWNER,
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf) },
      failOnStatusCode: false,
    });

    expect(response.status()).toBe(401);
  });

  test('walks the wizard from first boot to a signed-in dashboard', async ({ page }) => {
    await page.goto(`${APP}/setup`);

    // --- The claim gate ---
    await page.getByTestId('setup-token-input').fill(setupToken());
    await page.getByRole('button', { name: 'Continue' }).click();

    // --- Dependencies ---
    await expect(page.getByTestId('check-dependencies')).toBeVisible();
    await page.getByTestId('check-dependencies').click();

    for (const dependency of ['postgres', 'redis', 'clickhouse']) {
      await expect(page.getByTestId(`dependency-${dependency}`)).toHaveAttribute(
        'data-healthy',
        'true',
      );
    }

    await page.getByTestId('continue-from-dependencies').click();

    // --- Administrator ---
    await expect(page.getByLabel('Name')).toBeVisible();
    await page.getByLabel('Name').fill(OWNER.name);
    await page.getByLabel('Email').fill(OWNER.email);
    await page.getByLabel('Password').fill(OWNER.password);
    await page.getByTestId('step-submit').click();

    // --- Identity ---
    await expect(page.getByLabel('Instance name')).toBeVisible();
    await page.getByLabel('Instance name').fill('Externalia Links');
    await page.getByLabel('Primary short domain').fill('go.localhost');
    await page.getByTestId('step-submit').click();

    // --- Appearance ---
    await expect(page.getByLabel('Accent')).toBeVisible();
    await page.getByTestId('step-submit').click();

    // --- Analytics ---
    await expect(page.getByLabel('Retention')).toBeVisible();
    await page.getByTestId('step-submit').click();

    // --- Access ---
    await expect(page.getByLabel('Registration')).toBeVisible();
    await page.getByTestId('step-submit').click();

    // --- Mail, skipped ---
    await expect(page.getByTestId('skip-mail')).toBeVisible();
    await page.getByTestId('skip-mail').click();

    // --- Finish ---
    await expect(page.getByTestId('finish-setup')).toBeVisible();
    await page.getByTestId('finish-setup').click();

    await expect(page.getByTestId('setup-complete')).toBeVisible();

    // The session the wizard established is a real one: an endpoint that answers
    // 503 before installation and 401 without a session answers 200 here.
    const links = await page.request.get(`${APP}/api/v1/links`, {
      headers: { Accept: 'application/json' },
      failOnStatusCode: false,
    });

    expect(links.status()).toBe(200);

    await page.getByRole('button', { name: 'Open the dashboard' }).click();
    await expect(page).toHaveURL(`${APP}/`);
    await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();
  });

  test('closes setup permanently once installed', async ({ page, request }) => {
    const config = await request.get(`${APP}/api/v1/config`, {
      headers: { Accept: 'application/json' },
    });

    expect(((await config.json()) as { installed: boolean }).installed).toBe(true);

    const state = await request.get(`${APP}/api/v1/setup/state`, {
      headers: { Accept: 'application/json' },
      failOnStatusCode: false,
    });

    expect(state.status()).toBe(404);

    const response = await page.goto(`${APP}/setup`);

    expect(response?.status()).toBe(404);
  });
});

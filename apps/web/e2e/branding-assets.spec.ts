import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080';

const OPERATOR = { email: 'e2e@example.test', password: 'a quiet lantern drifts' };

/**
 * A real 1x1 PNG. The API decides format by decoding the file rather than by its
 * extension or declared type, so a fake buffer with a .png name is refused —
 * correctly, and uselessly for this test.
 */
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

async function signIn(page: import('@playwright/test').Page) {
  await page.goto(`${APP}/sign-in`);
  await page.getByLabel('Email').fill(OPERATOR.email);
  await page.getByLabel('Password').fill(OPERATOR.password);
  await page.getByTestId('sign-in').click();
  await expect(page).toHaveURL(`${APP}/`);
}

test.describe.configure({ mode: 'serial' });

test.describe('branding assets', () => {
  test('uploads a logo and serves it back', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/settings`);

    await page.getByTestId('asset-input-logo').setInputFiles({
      name: 'logo.png',
      mimeType: 'image/png',
      buffer: PNG,
    });

    const preview = page.getByTestId('asset-logo').locator('img');

    await expect(preview).toBeVisible();

    // The stored path is worthless if nothing serves it. This is the half that
    // was missing entirely: without public/storage there is no route to the
    // file at all, and the preview would render broken.
    const source = await preview.getAttribute('src');

    expect(source).toMatch(/^\/storage\//);

    const response = await page.request.get(`${APP}${source}`);

    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('image');
  });

  test('shows the wordmark in the header once one is set', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/settings`);

    await page.getByTestId('asset-input-wordmark').setInputFiles({
      name: 'wordmark.png',
      mimeType: 'image/png',
      buffer: PNG,
    });

    await expect(page.getByTestId('asset-wordmark').locator('img')).toBeVisible();

    await page.goto(`${APP}/`);

    // The wordmark takes the header in place of the logo and the name.
    const header = page.getByRole('banner').locator('img').first();

    await expect(header).toBeVisible();
    expect(await header.getAttribute('src')).toMatch(/^\/storage\//);
  });

  test('serves the favicon the operator uploaded', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/settings`);

    await page.getByTestId('asset-input-favicon').setInputFiles({
      name: 'favicon.png',
      mimeType: 'image/png',
      buffer: PNG,
    });

    await expect(page.getByTestId('asset-favicon').locator('img')).toBeVisible();

    await page.goto(`${APP}/`);

    const icon = page.locator('link[rel="icon"]');

    // Exactly one. A stray src/app/favicon.ico used to emit the framework's own
    // icon ahead of this one, leaving the browser to choose between them.
    await expect(icon).toHaveCount(1);
    expect(await icon.getAttribute('href')).toMatch(/^\/storage\//);

    const served = await page.request.get(`${APP}${await icon.getAttribute('href')}`);

    expect(served.status()).toBe(200);
  });

  test('falls back to a mark drawn from the accent, never the framework logo', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/settings`);

    await page.getByTestId('asset-clear-favicon').click();
    await expect(page.getByTestId('asset-favicon').getByText('None')).toBeVisible();

    await page.goto(`${APP}/`);

    const icon = page.locator('link[rel="icon"]');

    await expect(icon).toHaveCount(1);
    expect(await icon.getAttribute('href')).toBe('/brand-icon');

    const served = await page.request.get(`${APP}/brand-icon`);
    const body = await served.text();

    expect(served.headers()['content-type']).toContain('image/svg+xml');

    // Drawn from the instance's own accent, so an unbranded instance still has
    // an icon that belongs to it.
    const accent = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),
    );

    expect(body).toContain(accent);
  });

  test('clears an asset and falls back to what came before it', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/settings`);

    await page.getByTestId('asset-clear-wordmark').click();

    await expect(page.getByTestId('asset-wordmark').getByText('None')).toBeVisible();

    await page.goto(`${APP}/`);

    // With the wordmark gone the logo takes the header again.
    await expect(page.getByRole('banner').locator('img').first()).toBeVisible();
  });

  test('refuses a file that is not an image it can decode', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/settings`);

    await page.getByTestId('asset-input-logo').setInputFiles({
      name: 'not-really.png',
      mimeType: 'image/png',
      buffer: Buffer.from('this is not a png'),
    });

    // Format is decided by decoding, never by the extension or declared type.
    await expect(page.getByTestId('asset-logo').getByRole('alert')).toBeVisible();
  });

  // Branding is instance-wide state, so this file has to leave the instance as
  // it found it. Without this the interstitial suite fails on an assertion about
  // the instance name: its hold page renders an uploaded logo *instead of* the
  // name, so a logo left behind here silently changes what a later, unrelated
  // test sees.
  test('leaves the instance as it found it', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/settings`);

    await page.getByTestId('asset-clear-logo').click();
    await expect(page.getByTestId('asset-logo').getByText('None')).toBeVisible();

    await page.goto(`${APP}/`);

    // With nothing uploaded the header says the instance name in text.
    await expect(page.getByRole('banner').locator('img')).toHaveCount(0);
    await expect(page.getByRole('banner')).toContainText('Externalia Links');
  });
});

test('the colour mode toggle hydrates without a mismatch', async ({ page }) => {
  const complaints: string[] = [];

  page.on('console', (message) => {
    if (message.type() === 'error' && message.text().includes('hydrat')) {
      complaints.push(message.text());
    }
  });

  await signIn(page);

  await page.getByRole('button', { name: 'Dark' }).click();
  await page.reload();

  await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();

  // Reloaded with a stored theme is exactly the case that used to mismatch: the
  // server renders nothing pressed and the client's hydration render knows the
  // theme, so every attribute derived from it differed.
  await expect(page.getByRole('button', { name: 'Dark' })).toHaveAttribute('aria-pressed', 'true');

  expect(complaints).toEqual([]);
});

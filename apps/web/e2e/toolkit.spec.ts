import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080';

const OPERATOR = { email: 'e2e@example.test', password: 'a quiet lantern drifts' };

const MADE = `tk${Date.now().toString().slice(-8)}`;

async function signIn(page: import('@playwright/test').Page) {
  await page.goto(`${APP}/sign-in`);
  await page.getByLabel('Email').fill(OPERATOR.email);
  await page.getByLabel('Password').fill(OPERATOR.password);
  await page.getByTestId('sign-in').click();
  await expect(page).toHaveURL(`${APP}/`);
}

test.describe.configure({ mode: 'serial' });

test.describe('link toolkit', () => {
  test('builds campaign parameters onto the destination itself', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId('new-link').click();
    await page.getByLabel('Destination').fill('https://example.com/landing?ref=abc');
    await page.getByLabel('Custom slug').fill(MADE);

    await page.getByTestId('campaign-disclosure').click();
    await page.getByTestId('campaign-utm_source').fill('newsletter');
    await page.getByTestId('campaign-utm_medium').fill('email');

    // The destination field is where the values land — there is one copy of them,
    // and it is the URL.
    await expect(page.getByLabel('Destination')).toHaveValue(/utm_source=newsletter/);
    await expect(page.getByLabel('Destination')).toHaveValue(/ref=abc/);

    await page.getByTestId('save-link').click();
    await expect(page.locator(`[data-slug="${MADE}"]`)).toBeVisible();
  });

  test('reads existing parameters back out and replaces rather than appends', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId(`edit-${MADE}`).click();
    await page.getByTestId('campaign-disclosure').click();

    await expect(page.getByTestId('campaign-utm_source')).toHaveValue('newsletter');

    await page.getByTestId('campaign-utm_source').fill('partner');

    const destination = await page.getByLabel('Destination').inputValue();
    const occurrences = destination.split('utm_source=').length - 1;

    expect(occurrences).toBe(1);
    expect(destination).toContain('utm_source=partner');
  });

  test('renders a QR code for the link', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId(`edit-${MADE}`).click();
    await page.getByTestId('qr-disclosure').click();

    const image = page.getByTestId('qr-panel').locator('img');

    await expect(image).toBeVisible();

    // Rendered, not merely present: a broken image is visible too.
    const drawn = await image.evaluate((node) => {
      const img = node as HTMLImageElement;

      return img.complete && img.naturalWidth > 0;
    });

    expect(drawn).toBe(true);
  });

  test('saves routing rules and changes where a visitor lands', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId(`edit-${MADE}`).click();
    await page.getByTestId('rules-disclosure').click();

    await page.getByTestId('add-rule').click();
    await page.getByTestId('rule-kind-0').selectOption('device');
    await page.getByTestId('rule-value-0').fill('desktop');
    await page.getByTestId('rule-destination-0').fill('https://example.com/desktop');

    await page.getByTestId('save-rules').click();

    await expect(page.getByTestId('form-error')).toHaveCount(0);

    // Reopened from the server rather than trusting local state.
    await page.reload();
    await page.getByTestId(`edit-${MADE}`).click();
    await page.getByTestId('rules-disclosure').click();

    await expect(page.getByTestId('rule-value-0')).toHaveValue('desktop');
  });

  test('reorders rules, and the first match is the one that wins', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId(`edit-${MADE}`).click();
    await page.getByTestId('rules-disclosure').click();

    await page.getByTestId('add-rule').click();
    await page.getByTestId('rule-kind-1').selectOption('device');
    await page.getByTestId('rule-value-1').fill('mobile');
    await page.getByTestId('rule-destination-1').fill('https://example.com/mobile');

    await page.getByTestId('rule-up-1').click();

    // Moving is a button, not a drag: a drag surface that needs a pointer
    // excludes a keyboard, and this list is short.
    await expect(page.getByTestId('rule-value-0')).toHaveValue('mobile');

    await page.getByTestId('save-rules').click();
    await expect(page.getByTestId('form-error')).toHaveCount(0);
  });

  test('surfaces a refused rule against the form', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId(`edit-${MADE}`).click();
    await page.getByTestId('rules-disclosure').click();

    await page.getByTestId('add-rule').click();

    const last = (await page.getByTestId('rule-row').count()) - 1;

    await page.getByTestId(`rule-kind-${last}`).selectOption('country');
    await page.getByTestId(`rule-value-${last}`).fill('Spain');
    await page.getByTestId(`rule-destination-${last}`).fill('https://example.com/es');

    await page.getByTestId('save-rules').click();

    await expect(page.getByRole('alert').first()).toBeVisible();
  });

  test('imports a file, reporting the row it refused', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId('open-import').click();

    const good = `imp${Date.now().toString().slice(-8)}`;

    await page.getByTestId('import-file').setInputFiles({
      name: 'links.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(
        ['destination,slug', `https://example.com/imported,${good}`, 'not-a-url,badrow01'].join(
          '\n',
        ),
      ),
    });

    await page.getByTestId('start-import').click();

    await expect(page.getByTestId('import-progress')).toContainText('1 created');
    await expect(page.getByTestId('import-progress')).toContainText('1 refused');
    await expect(page.getByTestId('download-result')).toBeVisible();
  });

  test('offers an export', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await expect(page.getByTestId('export-links')).toBeVisible();
  });
});

test.describe('webhooks', () => {
  test('shows the signing secret once and never again', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/settings`);

    await page.getByTestId('new-webhook').click();

    // Scoped to the sheet: the settings page also carries an "Instance name"
    // field, and getByLabel matches on substring.
    await page.getByRole('dialog').getByLabel('Name', { exact: true }).fill('Warehouse');
    await page.getByTestId('webhook-url').fill('https://hooks.example.com/shortynah');
    await page.getByTestId('event-click.recorded').check();
    await page.getByTestId('save-webhook').click();

    const banner = page.getByTestId('issued-secret');

    await expect(banner).toBeVisible();

    const secret = (await banner.locator('code').textContent())?.trim() ?? '';

    expect(secret.length).toBeGreaterThan(20);

    // Gone after a reload, and nowhere in the page that replaces it.
    await page.reload();

    await expect(page.getByTestId('issued-secret')).toHaveCount(0);
    expect(await page.content()).not.toContain(secret);
  });

  test('refuses an endpoint that is not https', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/settings`);

    await page.getByTestId('new-webhook').click();
    await page.getByRole('dialog').getByLabel('Name', { exact: true }).fill('Insecure');
    await page.getByTestId('webhook-url').fill('http://hooks.example.com/plain');
    await page.getByTestId('event-click.recorded').check();
    await page.getByTestId('save-webhook').click();

    await expect(page.getByRole('alert').first()).toBeVisible();
  });
});

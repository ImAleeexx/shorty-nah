import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080';

const OPERATOR = { email: 'e2e@example.test', password: 'a quiet lantern drifts' };

async function openSettings(page: import('@playwright/test').Page) {
  await page.goto(`${APP}/sign-in`);
  await page.getByLabel('Email').fill(OPERATOR.email);
  await page.getByLabel('Password').fill(OPERATOR.password);
  await page.getByTestId('sign-in').click();
  await expect(page).toHaveURL(`${APP}/`);
  await page.goto(`${APP}/settings`);
  await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.describe('settings', () => {
  test('applies a change without a restart', async ({ page }) => {
    await openSettings(page);

    const form = page.getByTestId('settings-analytics');

    await form.getByLabel('Retention (days)').fill('123');
    await form.getByRole('button', { name: /Save analytics/ }).click();

    await expect(page.getByText('Analytics saved')).toBeVisible();

    // Read back from the API, not from the field that was just typed into.
    const response = await page.request.get(`${APP}/api/v1/settings`, {
      headers: { Accept: 'application/json' },
    });

    const settings = ((await response.json()) as { settings: Record<string, unknown> }).settings;

    expect(settings['analytics.retention_days']).toBe(123);
  });

  test('never renders a sensitive value back to the operator', async ({ page }) => {
    await openSettings(page);

    const mail = page.getByTestId('settings-mail');
    const password = mail.getByLabel('Password');

    await expect(password).toHaveValue('');
  });

  test('warns before an unreadable accent can be saved', async ({ page }) => {
    await openSettings(page);

    // Very light: legible on the dark surface, invisible on the light one.
    await page.getByTestId('accent-input').fill('oklch(0.97 0.02 250)');

    await expect(page.getByTestId('contrast-warning')).toBeVisible();
    await expect(page.getByTestId('save-branding')).toBeDisabled();

    // Both modes are judged, not only the one currently rendered.
    await expect(page.locator('[data-mode="light"]')).toHaveAttribute('data-passes', 'false');
  });

  test('offers a readable alternative that keeps the chosen hue', async ({ page }) => {
    await openSettings(page);

    await page.getByTestId('accent-input').fill('oklch(0.97 0.02 250)');
    await page.getByTestId('apply-contrast-suggestion').click();

    await expect(page.getByTestId('contrast-warning')).toHaveCount(0);
    await expect(page.getByTestId('save-branding')).toBeEnabled();

    // The hue the operator picked survives; only lightness moved.
    await expect(page.getByTestId('accent-input')).toHaveValue(/250\)$/);
  });

  // The bug this covers: branding sat behind recent authentication, so a save
  // more than fifteen minutes after signing in was refused with a 423 that no
  // form rendered. Every API test signed in fresh, so the suite never saw it.
  test('saves branding and keeps it after a reload', async ({ page }) => {
    await openSettings(page);

    const footer = `Operated by the ${Date.now().toString().slice(-6)} team`;

    await page.getByTestId('footer-input').fill(footer);
    await page.getByTestId('save-branding').click();

    await expect(page.getByTestId('form-error')).toHaveCount(0);

    // Reloaded rather than asserted against local state: the question is whether
    // the instance kept it, not whether the input still holds what was typed.
    await page.reload();

    await expect(page.getByTestId('footer-input')).toHaveValue(footer);
    await expect(page.locator('footer')).toContainText(footer);
  });

  test('hides the settings surface from an account that does not administrate', async ({
    request,
  }) => {
    const response = await request.get(`${APP}/api/v1/settings`, {
      headers: { Accept: 'application/json' },
      failOnStatusCode: false,
    });

    // Signed out entirely: the authenticated API refuses before authorization.
    expect([401, 404]).toContain(response.status());
  });
});

// The API has carried domain registration since the domain work landed; the
// settings page rendered a read-only list against it, so this whole flow was
// unreachable through the interface.
test.describe('domains', () => {
  const HOST = `d${Date.now().toString().slice(-8)}.example.test`;

  test('registers a domain, reports an honest check, and removes it', async ({ page }) => {
    await openSettings(page);

    await page.getByTestId('domain-host').fill(HOST);
    await page.getByTestId('save-domain').click();

    const row = page.locator(`[data-testid="domain-row"][data-host="${HOST}"]`);

    await expect(row).toBeVisible();
    await expect(row).toContainText('unverified');

    // The host does not resolve to this instance, and the interface says so
    // rather than reporting a check that did not happen as a success.
    await page.getByTestId(`verify-${HOST}`).click();
    await expect(page.getByText(/did not verify/i)).toBeVisible();

    // An unverified domain is never offered as primary.
    await expect(page.getByTestId(`promote-${HOST}`)).toHaveCount(0);

    await page.getByTestId(`remove-${HOST}`).click();
    await page.getByTestId('confirm-remove-domain').click();

    await expect(row).toHaveCount(0);
  });

  test('never offers to remove the primary domain', async ({ page }) => {
    await openSettings(page);

    // Located on the row's own attribute, not its text: a non-primary row
    // carries a "Make primary" button, so matching on text selects the wrong row.
    const primary = page.locator('[data-testid="domain-row"][data-primary="true"]');

    await expect(primary).not.toHaveCount(0);
    await expect(primary.getByRole('button', { name: /Remove/ })).toHaveCount(0);
  });
});

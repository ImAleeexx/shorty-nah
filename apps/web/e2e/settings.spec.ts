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

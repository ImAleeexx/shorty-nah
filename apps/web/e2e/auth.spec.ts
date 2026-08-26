import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080';

const OPERATOR = { email: 'e2e@example.test', password: 'a quiet lantern drifts' };

test.describe('authentication', () => {
  test('sends a signed-out viewer to sign in', async ({ page }) => {
    await page.goto(`${APP}/`);

    await expect(page).toHaveURL(/\/sign-in$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Sign in');
  });

  test('hides registration while the instance is closed', async ({ page, request }) => {
    const config = await request.get(`${APP}/api/v1/config`, {
      headers: { Accept: 'application/json' },
    });

    const mode = ((await config.json()) as { registration: { mode: string } }).registration.mode;

    // The fixture leaves the instance closed; if that ever changes, this test
    // should say so rather than quietly asserting nothing.
    expect(mode).toBe('closed');

    const response = await page.goto(`${APP}/register`);

    expect(response?.status()).toBe(404);
    await expect(page.getByRole('link', { name: 'Create one' })).toHaveCount(0);
  });

  test('refuses the wrong password without saying which half was wrong', async ({ page }) => {
    await page.goto(`${APP}/sign-in`);

    await page.getByLabel('Email').fill(OPERATOR.email);
    await page.getByLabel('Password').fill('not the password');
    await page.getByTestId('sign-in').click();

    // Targeted by its text rather than by role: Next renders an always-present
    // empty route announcer that also carries role="alert".
    await expect(page.getByText('do not match our records')).toBeVisible();
    await expect(page).toHaveURL(/\/sign-in$/);
  });

  test('signs in and reaches the dashboard', async ({ page }) => {
    await page.goto(`${APP}/sign-in`);

    await page.getByLabel('Email').fill(OPERATOR.email);
    await page.getByLabel('Password').fill(OPERATOR.password);
    await page.getByTestId('sign-in').click();

    await expect(page).toHaveURL(`${APP}/`);
    await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();
  });
});

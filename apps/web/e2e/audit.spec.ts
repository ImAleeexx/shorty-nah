import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080';

const OPERATOR = { email: 'e2e@example.test', password: 'a quiet lantern drifts' };

async function signIn(page: import('@playwright/test').Page) {
  await page.goto(`${APP}/sign-in`);
  await page.getByLabel('Email').fill(OPERATOR.email);
  await page.getByLabel('Password').fill(OPERATOR.password);
  await page.getByTestId('sign-in').click();
  await expect(page).toHaveURL(`${APP}/`);
}

test.describe('audit log', () => {
  test('records the sign-in that just happened, newest first', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/audit`);

    await expect(page.getByRole('heading', { name: 'Audit log' })).toBeVisible();

    const rows = page.getByTestId('audit-row');
    await expect(rows).not.toHaveCount(0);

    // The sign-in that opened this page is the most recent thing that happened.
    await expect(rows.first()).toHaveAttribute('data-action', 'auth.sign_in.succeeded');
  });

  test('filters by action', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/audit`);

    await page.getByTestId('audit-action').selectOption('instance.installed');

    await expect(page).toHaveURL(/action=instance\.installed/);

    const rows = page.getByTestId('audit-row');
    const count = await rows.count();

    for (let index = 0; index < count; index++) {
      await expect(rows.nth(index)).toHaveAttribute('data-action', 'instance.installed');
    }
  });

  test('shows a derived source, never an address', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/audit`);

    const body = await page.locator('body').innerText();

    expect(body).not.toMatch(/\b\d{1,3}(\.\d{1,3}){3}\b/);
  });

  test('offers no control that edits or deletes an entry', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/audit`);

    // Read-only by construction: the page has filters and nothing else.
    await expect(page.getByRole('button', { name: /delete|remove|edit/i })).toHaveCount(0);
  });
});

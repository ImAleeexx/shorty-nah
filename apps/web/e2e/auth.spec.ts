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

test.describe('the sign-in page', () => {
  test('puts the form on an elevated surface like every other card', async ({ page }) => {
    await page.goto(`${APP}/sign-in`);

    const card = page
      .locator('main div')
      .filter({ has: page.getByLabel('Email') })
      .last();

    const styles = await card.evaluate((node) => {
      // The nearest ancestor that actually carries the elevation.
      let current: HTMLElement | null = node as HTMLElement;

      while (current !== null && getComputedStyle(current).boxShadow === 'none') {
        current = current.parentElement;
      }

      return current === null ? null : getComputedStyle(current).boxShadow;
    });

    // The form used to sit on bare canvas, which in a system that separates
    // surfaces by elevation reads as a page that has not finished loading.
    expect(styles).not.toBeNull();
    expect(styles).not.toBe('none');
  });

  test('says whose instance it is', async ({ page }) => {
    await page.goto(`${APP}/sign-in`);

    // From branding, above the card, so the heading does not spend the largest
    // text on the page repeating it.
    await expect(page.getByRole('main')).toContainText('Externalia Links');
    await expect(page.getByRole('heading', { name: 'Sign in', exact: true })).toBeVisible();
  });

  test('lets a viewer set the colour mode before signing in', async ({ page }) => {
    await page.goto(`${APP}/sign-in`);

    // Someone signing in on a machine set to the mode they do not want should
    // not have to sign in first to change it.
    await expect(page.getByRole('group', { name: 'Colour mode' })).toBeVisible();
  });

  test('starts with the cursor in the email field', async ({ page }) => {
    await page.goto(`${APP}/sign-in`);

    await expect(page.getByLabel('Email')).toBeFocused();
  });

  test('explains the absence of a sign-up link rather than leaving a gap', async ({ page }) => {
    await page.goto(`${APP}/sign-in`);

    await expect(page.getByText(/created by an operator/i)).toBeVisible();
  });
});

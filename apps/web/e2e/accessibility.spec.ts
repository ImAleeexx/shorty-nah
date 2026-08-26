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

test.describe('keyboard and screen-reader access', () => {
  test('reaches sign in and submits it without a pointer', async ({ page }) => {
    await page.goto(`${APP}/sign-in`);

    await page.keyboard.press('Tab');

    // Tab order starts inside the form rather than in decoration.
    const firstStop = await page.evaluate(() => document.activeElement?.getAttribute('name'));

    expect(['email', 'password']).toContain(firstStop);

    await page.getByLabel('Email').fill(OPERATOR.email);
    await page.getByLabel('Password').fill(OPERATOR.password);
    await page.getByLabel('Password').press('Enter');

    await expect(page).toHaveURL(`${APP}/`);
  });

  test('labels every control on the link form', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);
    await page.getByTestId('new-link').click();

    // Every field is reachable by its visible label, which is the same thing a
    // screen reader announces.
    for (const label of [
      'Destination',
      'Domain',
      'Custom slug',
      'Redirect mode',
      'Password',
      'Expires',
      'Click limit',
      'Tags',
    ]) {
      // Scoped to the sheet and matched exactly: "Domain" also names the list's
      // filter, and a substring match would happily assert against that instead.
      await expect(page.getByRole('dialog').getByLabel(label, { exact: true })).toBeVisible();
    }
  });

  test('gives every icon-only control an accessible name', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    const unnamed = await page.evaluate(
      () =>
        [...document.querySelectorAll('button, a')].filter((node) => {
          const text = (node.textContent ?? '').trim();
          const label = node.getAttribute('aria-label') ?? '';
          const title = node.getAttribute('title') ?? '';

          return text === '' && label === '' && title === '';
        }).length,
    );

    expect(unnamed).toBe(0);
  });

  test('exposes one first-level heading per page', async ({ page }) => {
    await signIn(page);

    for (const path of ['/', '/links', '/settings', '/people']) {
      await page.goto(`${APP}${path}`);

      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    }
  });

  test('marks the current page in navigation', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await expect(page.locator('nav a[aria-current="page"]')).toHaveText('Links');
  });

  test('keeps a visible focus ring on keyboard focus', async ({ page }) => {
    await page.goto(`${APP}/sign-in`);

    await page.getByLabel('Email').focus();

    const outline = await page
      .getByLabel('Email')
      .evaluate((node) => getComputedStyle(node).outlineWidth);

    expect(outline).not.toBe('0px');
  });

  test('states the next action on an empty search rather than only absence', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId('link-search').fill('nothing-matches-this-at-all');

    await expect(page.getByRole('heading', { name: 'Nothing matches' })).toBeVisible();

    // An empty state that only reports absence leaves the viewer stuck.
    await expect(page.getByRole('button', { name: 'Clear filters' })).toBeVisible();
  });

  test('sets the empty-state heading in the editorial serif', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);
    await page.getByTestId('link-search').fill('nothing-matches-this-at-all');

    const family = await page
      .getByRole('heading', { name: 'Nothing matches' })
      .evaluate((node) => getComputedStyle(node).fontFamily);

    expect(family.toLowerCase()).toContain('editorial');
  });
});

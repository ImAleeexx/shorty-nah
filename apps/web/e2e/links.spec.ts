import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080';

const OPERATOR = { email: 'e2e@example.test', password: 'a quiet lantern drifts' };

// Unique per run. A deleted link keeps its slug reserved — deliberately, so old
// traffic cannot be redirected somewhere new by whoever claims it next — which
// means a fixed slug makes this spec pass exactly once per database.
const MADE = `made${Date.now().toString().slice(-8)}`;
const GUARDED = `grd${Date.now().toString().slice(-8)}`;

async function signIn(page: import('@playwright/test').Page) {
  await page.goto(`${APP}/sign-in`);
  await page.getByLabel('Email').fill(OPERATOR.email);
  await page.getByLabel('Password').fill(OPERATOR.password);
  await page.getByTestId('sign-in').click();
  await expect(page).toHaveURL(`${APP}/`);
}

test.describe.configure({ mode: 'serial' });

test.describe('link management', () => {
  test('lists the fixture links', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await expect(page.getByTestId('link-row')).not.toHaveCount(0);
    await expect(page.locator('[data-slug="e2edrct1"]')).toBeVisible();
  });

  test('filters the list without a round trip', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    const before = await page.getByTestId('link-row').count();

    await page.getByTestId('link-search').fill('e2ehold1');

    await expect(page.locator('[data-slug="e2ehold1"]')).toBeVisible();
    await expect(page.locator('[data-slug="e2edrct1"]')).toHaveCount(0);
    expect(before).toBeGreaterThan(1);

    await page.getByTestId('link-search').fill('');
    await expect(page.getByTestId('link-row')).toHaveCount(before);
  });

  test('surfaces a server validation error against its own field', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId('new-link').click();

    // A destination the API refuses: loopback is rejected after resolution, and
    // the refusal must land on the destination field rather than as a banner.
    await page.getByLabel('Destination').fill('http://127.0.0.1:9/private');
    await page.getByTestId('save-link').click();

    await expect(page.getByText(/destination/i).first()).toBeVisible();
    await expect(page.getByTestId('save-link')).toBeVisible();
  });

  test('creates a link and shows it in the list', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId('new-link').click();
    await page.getByLabel('Destination').fill('https://example.com/from-the-browser');
    await page.getByLabel('Custom slug').fill(MADE);
    await page.getByTestId('save-link').click();

    await expect(page.locator(`[data-slug="${MADE}"]`)).toBeVisible();
  });

  test('edits the link it just created', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId(`edit-${MADE}`).click();
    await page.getByLabel('Destination').fill('https://example.com/edited');
    await page.getByTestId('save-link').click();

    await expect(
      page.locator(`[data-slug="${MADE}"]`).getByText('https://example.com/edited'),
    ).toBeVisible();
  });

  test('deletes it again', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page
      .locator(`[data-slug="${MADE}"]`)
      .getByRole('button', { name: /Delete/ })
      .click();

    await expect(page.locator(`[data-slug="${MADE}"]`)).toHaveCount(0);
  });
});

test('editing a protected link without retyping the password keeps it protected', async ({
  page,
}) => {
  await signIn(page);
  await page.goto(`${APP}/links`);

  await page.getByTestId('new-link').click();
  await page.getByLabel('Destination').fill('https://example.com/guarded');
  await page.getByLabel('Custom slug').fill(GUARDED);
  await page.getByRole('dialog').getByLabel('Password', { exact: true }).fill('a-shared-secret');
  await page.getByTestId('save-link').click();

  const row = page.locator(`[data-slug="${GUARDED}"]`);
  await expect(row).toContainText('password');

  // Change only the destination, leaving the password box empty exactly as its
  // hint instructs. The link must stay protected.
  await page.getByTestId(`edit-${GUARDED}`).click();
  await page.getByLabel('Destination').fill('https://example.com/still-guarded');
  await page.getByTestId('save-link').click();

  await expect(row).toContainText('https://example.com/still-guarded');
  await expect(row).toContainText('password');

  await row.getByRole('button', { name: /Delete/ }).click();
  await expect(page.locator(`[data-slug="${GUARDED}"]`)).toHaveCount(0);
});

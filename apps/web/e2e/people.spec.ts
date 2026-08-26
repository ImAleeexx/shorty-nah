import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080';

const OPERATOR = { email: 'e2e@example.test', password: 'a quiet lantern drifts' };

// Unique per run: an invitation is a record that survives revocation, so a fixed
// address accumulates rows across runs and makes every locator ambiguous.
const INVITEE = `invited-${Date.now()}@example.test`;

async function openPeople(page: import('@playwright/test').Page) {
  await page.goto(`${APP}/sign-in`);
  await page.getByLabel('Email').fill(OPERATOR.email);
  await page.getByLabel('Password').fill(OPERATOR.password);
  await page.getByTestId('sign-in').click();
  await expect(page).toHaveURL(`${APP}/`);
  await page.goto(`${APP}/people`);
  await expect(page.getByRole('heading', { name: 'People' })).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.describe('people', () => {
  test('lists the accounts on the instance', async ({ page }) => {
    await openPeople(page);

    await expect(page.locator(`[data-email="${OPERATOR.email}"]`)).toBeVisible();
  });

  test('cannot change or remove the account it is signed in as', async ({ page }) => {
    await openPeople(page);

    const row = page.locator(`[data-email="${OPERATOR.email}"]`);

    await expect(row.getByRole('combobox')).toBeDisabled();
    await expect(row.getByRole('button', { name: /Remove/ })).toBeDisabled();
  });

  test('issues an invitation and shows its code exactly once', async ({ page }) => {
    await openPeople(page);

    await page.getByTestId('new-invitation').click();
    await page.getByLabel('Email').fill(INVITEE);
    await page.getByTestId('send-invitation').click();

    const issued = page.getByTestId('issued-invitation');
    await expect(issued).toBeVisible();
    await expect(issued).toContainText(INVITEE);

    const code = await issued.locator('code').textContent();
    expect(code).toBeTruthy();

    // Reloading must not show it again: only the hash was stored.
    await page.reload();
    await expect(page.getByTestId('issued-invitation')).toHaveCount(0);
    await expect(page.getByTestId('invitation-row').filter({ hasText: INVITEE })).toHaveCount(1);
  });

  test('revokes the invitation, keeping it as a record', async ({ page }) => {
    await openPeople(page);

    const row = page.getByTestId('invitation-row').filter({ hasText: INVITEE });

    await row.getByRole('button', { name: /Revoke/ }).click();

    // Revocation is a state, not a deletion: who was offered access stays part
    // of the record, and the row can no longer be revoked twice.
    await expect(row).toHaveAttribute('data-state', 'revoked');
    await expect(row.getByRole('button', { name: /Revoke/ })).toHaveCount(0);
  });

  test('is not reachable without an administrating account', async ({ request }) => {
    const response = await request.get(`${APP}/api/v1/users`, {
      headers: { Accept: 'application/json' },
      failOnStatusCode: false,
    });

    expect([401, 404]).toContain(response.status());
  });
});

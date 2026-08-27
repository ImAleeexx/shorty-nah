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

test.describe('overview', () => {
  // The screen this replaces rendered a literal zero and an empty state no
  // matter what the instance held, so the assertion that matters is that the
  // figures move with the data at all.
  test('reports figures it measured rather than zeroes', async ({ page }) => {
    await signIn(page);

    const links = page.getByTestId('stat-links').locator('span').nth(1);
    const clicks = page.getByTestId('stat-clicks').locator('span').nth(1);

    await expect(links).not.toHaveText('0');
    await expect(clicks).not.toHaveText('0');
  });

  test('draws the activity it recorded and names the countries', async ({ page }) => {
    await signIn(page);

    await expect(page.getByTestId('overview-sparkline')).toBeVisible();

    // One bar per day of the window, including the quiet ones.
    const bars = page.getByTestId('overview-sparkline').locator('> div');
    expect(await bars.count()).toBeGreaterThan(27);

    await expect(page.getByTestId('overview-countries')).toBeVisible();
  });

  test('lists real links instead of an empty state', async ({ page }) => {
    await signIn(page);

    await expect(page.getByTestId('overview-link').first()).toBeVisible();
    await expect(page.getByText('No links yet')).toHaveCount(0);
  });
});

test.describe('the states that were missing', () => {
  test('serves a branded page for an address that resolves to nothing', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/nothing-is-here`);

    await expect(page.getByText('There is nothing at this address')).toBeVisible();
    await expect(page.getByRole('link', { name: /Back to the overview/ })).toBeVisible();
  });

  test('offers a skip link to a keyboard before the navigation', async ({ page }) => {
    await signIn(page);

    // Waited for before measuring: page.evaluate waits for nothing, and reading
    // the document the instant navigation resolves finds an empty one.
    await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();

    // Asserted on document order rather than by pressing Tab. Next's
    // development overlay mounts its own focusable portal and cycles focus
    // inside it; it does not exist in a production build, and testing around it
    // would be testing the overlay rather than the page.
    const first = await page.evaluate(() => {
      const focusable = document.querySelectorAll<HTMLElement>(
        'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])',
      );

      for (const node of focusable) {
        if (node.closest('nextjs-portal') !== null) {
          continue;
        }

        return (node.textContent ?? '').trim();
      }

      return '';
    });

    expect(first).toBe('Skip to content');

    const skip = page.getByRole('link', { name: 'Skip to content' });

    await skip.focus();

    // And it is genuinely visible once focused, rather than staying clipped.
    await expect(skip).toBeVisible();

    await skip.press('Enter');
    await expect(page.locator('#content')).toBeFocused();
  });
});

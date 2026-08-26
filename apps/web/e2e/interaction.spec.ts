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

test.describe('command palette', () => {
  test('opens on the keyboard shortcut and returns focus on dismissal', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.getByTestId('link-search').focus();

    await page.keyboard.press('ControlOrMeta+k');
    await expect(page.getByTestId('palette-input')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page.getByTestId('palette-input')).toHaveCount(0);

    // Focus goes back where it was, not to the top of the document.
    await expect(page.getByTestId('link-search')).toBeFocused();
  });

  test('opens instantly, with no transition', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.keyboard.press('ControlOrMeta+k');

    const input = page.getByTestId('palette-input');
    await expect(input).toBeVisible();

    // A surface opened a hundred times a day gets no animation at all.
    const duration = await input.evaluate((node) => {
      const dialog = node.closest('[cmdk-dialog]') ?? node;

      return getComputedStyle(dialog).transitionDuration;
    });

    // Every transition on the surface is zero: a comma-separated list means one
    // duration per property, and all of them must be none.
    expect(duration.split(',').every((value) => value.trim() === '0s')).toBe(true);
  });

  test('finds a link by its slug', async ({ page }) => {
    await signIn(page);
    await page.goto(`${APP}/links`);

    await page.keyboard.press('ControlOrMeta+k');
    await page.getByTestId('palette-input').fill('e2ehold1');

    await expect(page.getByRole('option', { name: /e2ehold1/ })).toBeVisible();
  });
});

test.describe('action feedback', () => {
  test('toasts a copy and replaces it rather than stacking on retrigger', async ({
    page,
    context,
  }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await signIn(page);
    await page.goto(`${APP}/links`);

    const copy = page.locator('[data-slug="e2edrct1"]').getByRole('button', { name: /Copy/ });

    await copy.click();
    await expect(page.getByText('Link copied')).toBeVisible();

    // Rapid retriggering must replace the toast in place, not queue duplicates.
    await copy.click();
    await copy.click();
    await copy.click();

    await expect(page.getByText('Link copied')).toHaveCount(1);
  });

  test('renders the toast in the active colour mode', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await signIn(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto(`${APP}/links`);

    await page.locator('[data-slug="e2edrct1"]').getByRole('button', { name: /Copy/ }).click();

    const toast = page.locator('[data-sonner-toast]').first();
    await expect(toast).toBeVisible();

    // Asserted against the rendered colour rather than an attribute: what
    // matters is that the toast picked up the same surface token the rest of the
    // page resolved in dark mode, not that a class name was applied.
    const [painted, expected] = await Promise.all([
      toast.evaluate((node) => getComputedStyle(node).backgroundColor),
      page.evaluate(() => {
        const probe = document.createElement('div');
        probe.style.color = 'var(--surface)';
        document.body.append(probe);
        const resolved = getComputedStyle(probe).color;
        probe.remove();

        return resolved;
      }),
    ]);

    expect(painted).toBe(expected);
    await expect(page.locator('[data-sonner-toaster]')).toHaveAttribute(
      'data-sonner-theme',
      'dark',
    );
  });
});

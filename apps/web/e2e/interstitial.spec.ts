import { expect, test } from '@playwright/test';

/**
 * Drives the interstitial in a real browser.
 *
 * The fixture is seeded by `make e2e-fixture`. Chrome resolves any *.localhost
 * name to loopback, which is what lets the browser reach a short domain through
 * the edge without touching /etc/hosts.
 */
const SHORT_URL = 'http://go.localhost:8080/e2ehold1';
const DESTINATION = 'http://localhost:8080/sign-in';

test.describe('interstitial mode', () => {
  test('renders the operator branding', async ({ page }) => {
    await page.goto(SHORT_URL);

    await expect(page.getByText('Externalia Links')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Taking you there' })).toBeVisible();

    // Branding reaches the page as CSS custom properties, so the accent and
    // radius are observable on the rendered document rather than only in source.
    const accent = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),
    );
    const radius = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--radius').trim(),
    );

    expect(accent).toContain('oklch');
    expect(radius).toBe('14px');
  });

  test('navigates to the destination after the configured delay', async ({ page }) => {
    const startedAt = Date.now();

    await page.goto(SHORT_URL);
    await page.waitForURL(DESTINATION, { timeout: 15_000 });

    // The hold page is meant to hold: arriving instantly would mean the delay
    // never applied.
    expect(Date.now() - startedAt).toBeGreaterThanOrEqual(500);
    expect(page.url()).toBe(DESTINATION);
  });

  test('reports exactly one beacon for one view', async ({ page }) => {
    const beacons: string[] = [];

    page.on('request', (request) => {
      if (request.url().includes('/api/clicks/beacon')) {
        beacons.push(request.url());
      }
    });

    await page.goto(SHORT_URL);
    await page.waitForURL(DESTINATION, { timeout: 15_000 });

    // Duplicate beacons would double-count a single visit.
    expect(beacons).toHaveLength(1);
  });

  test('runs its inline script under the content security policy', async ({ page }) => {
    const violations: string[] = [];

    page.on('console', (message) => {
      if (message.text().includes('Content Security Policy')) {
        violations.push(message.text());
      }
    });

    await page.goto(SHORT_URL);
    await page.waitForURL(DESTINATION, { timeout: 15_000 });

    // The nonce is what makes a policy with no unsafe-inline workable; a
    // mismatch would block the page's own script and it would never navigate.
    expect(violations).toEqual([]);
  });

  test('reaches the destination with scripting disabled', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();

    await page.goto(SHORT_URL);

    // The noscript meta refresh is the only route through here.
    await page.waitForURL(DESTINATION, { timeout: 15_000 });

    expect(page.url()).toBe(DESTINATION);

    await context.close();
  });
});

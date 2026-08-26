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

async function openReport(page: import('@playwright/test').Page) {
  await signIn(page);
  await page.goto(`${APP}/links`);
  await page.getByTestId('report-e2edrct1').click();
  await expect(page.getByRole('heading', { name: /e2edrct1/ })).toBeVisible();
}

test.describe('analytics', () => {
  test('renders figures that match the API', async ({ page }) => {
    await openReport(page);

    const id = page.url().split('/').pop() ?? '';

    const response = await page.request.get(`${APP}/api/v1/links/${id}/report`, {
      headers: { Accept: 'application/json' },
    });

    const report = (await response.json()) as {
      totals: { counted: number; visitors: number; automated: number; duplicates: number };
    };

    // The page must show what the API said, not a number computed a second time
    // in the browser from a different source.
    for (const [label, value] of [
      ['Counted', report.totals.counted],
      ['Visitors', report.totals.visitors],
      ['Filtered', report.totals.automated],
      ['Duplicates', report.totals.duplicates],
    ] as const) {
      // Addressed by the tile's own hook: "Visitors" is also a chart caption, and
      // a text locator would happily assert against the chart instead.
      const tile = page.getByTestId(`stat-${label.toLowerCase()}`);

      await expect(tile).toContainText(value.toLocaleString());
    }
  });

  test('draws both time series without a second axis', async ({ page }) => {
    await openReport(page);

    await expect(page.getByTestId('chart-counted')).toBeVisible();
    await expect(page.getByTestId('chart-visitors')).toBeVisible();

    // Small multiples rather than one chart with two scales: each figure owns a
    // single y-axis, so there is exactly one per chart.
    const axes = await page.getByTestId('chart-counted').locator('.recharts-yAxis').count();

    expect(axes).toBe(1);
  });

  test('shows a breakdown of countries', async ({ page }) => {
    await openReport(page);

    const countries = page.getByTestId('breakdown-countries');

    await expect(countries).toBeVisible();
    await expect(countries.locator('li')).not.toHaveCount(0);
  });

  test('does not count up on initial load', async ({ page }) => {
    await openReport(page);

    const tile = page.locator('.tabular').first();
    const first = await tile.textContent();

    await page.waitForTimeout(400);

    // The figure a viewer came to read is present immediately and does not
    // animate towards itself.
    expect(await tile.textContent()).toBe(first);
  });

  test('virtualizes the raw drill-down over several thousand rows', async ({ page }) => {
    await openReport(page);

    const table = page.getByTestId('event-table');
    await expect(table).toBeVisible();

    const id = page.url().split('/').pop() ?? '';
    const response = await page.request.get(`${APP}/api/v1/links/${id}/events?per_page=1`, {
      headers: { Accept: 'application/json' },
    });

    const total = ((await response.json()) as { meta: { total: number } }).meta.total;

    expect(total).toBeGreaterThan(2000);

    // The property that makes thousands of rows scroll: only the window is in
    // the document, never the corpus.
    const rendered = await page.getByTestId('event-row').count();

    expect(rendered).toBeGreaterThan(0);
    expect(rendered).toBeLessThan(100);
  });

  test('exports the period as a CSV for an authorized operator', async ({ page }) => {
    await openReport(page);

    const id = page.url().split('/').pop() ?? '';

    const response = await page.request.get(`${APP}/api/v1/links/${id}/export`, {
      headers: { Accept: 'text/csv' },
    });

    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('csv');

    const body = await response.text();

    // No address column: raw addresses are never persisted, so an export cannot
    // contain one.
    expect(body.split('\n')[0]).not.toMatch(/ip|address/i);
    expect(body.split('\n').length).toBeGreaterThan(1);
  });
});

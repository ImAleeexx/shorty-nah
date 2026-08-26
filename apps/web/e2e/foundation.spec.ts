import { expect, test } from '@playwright/test';

const APP = 'http://localhost:8080/';
const CONFIG = 'http://localhost:8080/api/v1/config';

/**
 * Read from the API rather than hardcoded. Two fixtures set branding, and a test
 * that pins a literal breaks whenever the other one runs — which says nothing
 * about the code.
 */
async function expectedBranding(request: import('@playwright/test').APIRequestContext) {
  const response = await request.get(CONFIG);
  const config = (await response.json()) as {
    branding: { accent: string | null; radius: number | null };
  };

  return config.branding;
}

test.describe('design foundation', () => {
  test('paints the operator accent on first render', async ({ page, request }) => {
    const expected = await expectedBranding(request);

    await page.goto(APP);

    // Read from the document rather than the source: this asserts the value
    // actually reached the rendered page, not merely that it was serialised.
    const accent = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),
    );
    const radius = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--radius').trim(),
    );

    expect(accent).toContain('oklch');
    expect(radius).toBe(`${expected.radius}px`);
  });

  test('derives hover, active and muted states from the one accent', async ({ page }) => {
    await page.goto(APP);

    const states = await page.evaluate(() => {
      const styles = getComputedStyle(document.documentElement);

      return {
        accent: styles.getPropertyValue('--accent').trim(),
        hover: styles.getPropertyValue('--accent-hover').trim(),
        active: styles.getPropertyValue('--accent-active').trim(),
        muted: styles.getPropertyValue('--accent-muted').trim(),
      };
    });

    // An accent picker exposes one input; every state below is computed from it.
    for (const value of Object.values(states)) {
      expect(value).not.toBe('');
    }

    expect(states.hover).not.toBe(states.accent);
    expect(states.active).not.toBe(states.hover);
    expect(states.muted).not.toBe(states.accent);
  });

  test('resolves colour utilities to variables rather than literals', async ({ page }) => {
    await page.goto(APP);

    const card = page.locator('[data-testid="links-card"]');
    const before = await card.evaluate((node) => getComputedStyle(node).backgroundColor);

    // Changing the token must change the rendered colour. If Tailwind had baked a
    // literal, this would do nothing — and runtime rebranding would be impossible.
    await page.evaluate(() => {
      document.documentElement.style.setProperty('--surface', 'oklch(0.5 0.2 140)');
    });

    const after = await card.evaluate((node) => getComputedStyle(node).backgroundColor);

    expect(after).not.toBe(before);
  });

  test('collapses the bento grid to one column below 768px', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(APP);

    const wide = await page.evaluate(() => {
      const grid = document.querySelector('[data-testid="bento-grid"]');

      return grid === null ? 0 : getComputedStyle(grid).gridTemplateColumns.split(' ').length;
    });

    await page.setViewportSize({ width: 640, height: 900 });

    const narrow = await page.evaluate(() => {
      const grid = document.querySelector('[data-testid="bento-grid"]');

      return grid === null ? 0 : getComputedStyle(grid).gridTemplateColumns.split(' ').length;
    });

    expect(wide).toBe(12);
    // An asymmetric layout on a phone produces overlapping touch targets, not a
    // clever composition.
    expect(narrow).toBe(1);
  });

  test('resets every span override on a narrow viewport', async ({ page }) => {
    await page.setViewportSize({ width: 640, height: 900 });
    await page.goto(APP);

    // Measured rather than read from a computed keyword: what matters is that no
    // cell is narrower than the grid, because a surviving span would overlap
    // touch targets.
    const widths = await page.evaluate(() => {
      const grid = document.querySelector('[data-testid="bento-grid"]');
      const cells = Array.from(document.querySelectorAll('[data-testid="bento-cell"]'));

      return {
        grid: grid === null ? 0 : Math.round(grid.getBoundingClientRect().width),
        cells: cells.map((cell) => Math.round(cell.getBoundingClientRect().width)),
      };
    });

    expect(widths.cells.length).toBeGreaterThan(0);

    for (const width of widths.cells) {
      expect(width).toBe(widths.grid);
    }
  });

  test('uses hairline borders and no stock shadow on a card', async ({ page, request }) => {
    const expected = await expectedBranding(request);

    await page.goto(APP);

    const card = page.locator('[data-testid="links-card"]');
    const styles = await card.evaluate((node) => {
      const computed = getComputedStyle(node);

      return {
        borderWidth: computed.borderTopWidth,
        boxShadow: computed.boxShadow,
        radius: computed.borderTopLeftRadius,
      };
    });

    expect(styles.borderWidth).toBe('1px');
    expect(styles.boxShadow).toBe('none');
    expect(styles.radius).toBe(`${expected.radius}px`);
  });

  test('honours a reduced-motion preference', async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();

    await page.goto(APP);

    const durations = await page.evaluate(() =>
      Array.from(document.querySelectorAll('button')).map(
        (node) => getComputedStyle(node).transitionDuration,
      ),
    );

    expect(durations.length).toBeGreaterThan(0);

    // Reduced motion means gentler, not none — but movement is suppressed, and a
    // transition this short is imperceptible.
    for (const duration of durations) {
      expect(parseFloat(duration)).toBeLessThan(0.05);
    }

    await context.close();
  });

  test('keeps press feedback short enough to feel immediate', async ({ page }) => {
    await page.goto(APP);

    const duration = await page
      .locator('button', { hasText: 'New link' })
      .evaluate((node) => getComputedStyle(node).transitionDuration);

    // Feedback, not animation: a press must read as instant acknowledgement.
    expect(parseFloat(duration)).toBeLessThanOrEqual(0.16);
  });

  test('applies no scroll-entry animation to dashboard content', async ({ page }) => {
    await page.goto(APP);

    const animated = await page.evaluate(() => {
      const cards = Array.from(document.querySelectorAll('[data-testid="bento-cell"]'));

      return cards.filter((card) => {
        const computed = getComputedStyle(card);

        return computed.animationName !== 'none' || parseFloat(computed.transitionDuration) > 0;
      }).length;
    });

    // Content is present when the page is. A dashboard is opened dozens of times
    // a day; revealing it on scroll makes it feel slow every single time.
    expect(animated).toBe(0);
  });

  test('names no banned typeface in the resolved font stack', async ({ page }) => {
    await page.goto(APP);

    const stack = await page.evaluate(() => getComputedStyle(document.body).fontFamily);

    for (const banned of ['Inter', 'Roboto', 'Helvetica', 'Open Sans', 'Arial']) {
      expect(stack).not.toContain(banned);
    }

    expect(stack).toContain('Geist');
  });

  test('switches colour mode without losing the accent', async ({ page }) => {
    await page.goto(APP);

    const accentBefore = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),
    );

    await page.getByRole('button', { name: 'Dark' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    const accentAfter = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),
    );

    // Branding survives a mode change; only the surfaces around it move.
    expect(accentAfter).toBe(accentBefore);

    await page.getByRole('button', { name: 'Light' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  });
});

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

const OPERATOR = { email: 'e2e@example.test', password: 'a quiet lantern drifts' };

/**
 * The dashboard is the surface these assertions are about, and it now requires a
 * session — an unauthenticated viewer is sent to sign in, which is the correct
 * product behaviour rather than something to work around.
 */
async function signIn(page: import('@playwright/test').Page) {
  await page.goto('http://localhost:8080/sign-in');
  await page.getByLabel('Email').fill(OPERATOR.email);
  await page.getByLabel('Password').fill(OPERATOR.password);
  await page.getByTestId('sign-in').click();
  await expect(page).toHaveURL(APP);
}

test.describe('design foundation', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page);
  });

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

  test('gives every cell in a row the same height', async ({ page }) => {
    await page.setViewportSize({ width: 1400, height: 900 });
    await page.goto(APP);

    // Grouped by where each cell starts, so this describes rows without knowing
    // how many there are or which spans made them.
    const rows = await page.evaluate(() => {
      const grouped = new Map<number, number[]>();

      for (const cell of document.querySelectorAll('[data-testid="bento-cell"]')) {
        const box = cell.getBoundingClientRect();
        const top = Math.round(box.top);

        grouped.set(top, [...(grouped.get(top) ?? []), Math.round(box.height)]);
      }

      return [...grouped.values()];
    });

    expect(rows.length).toBeGreaterThan(0);

    // This has regressed once. Cells were briefly sized to their own content, on
    // the reasoning that an elevated card holding nothing looks unfinished —
    // which traded one problem for a worse one: three cards at three different
    // heights in a row have no shared baseline, and a row without a baseline
    // reads as broken rather than as airy.
    for (const heights of rows) {
      expect(new Set(heights).size, `heights in one row: ${heights.join(', ')}`).toBe(1);
    }
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

  test('separates a card by a tinted shadow rather than a border', async ({ page, request }) => {
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

    // This test used to assert a 1px border and boxShadow: none. The direction
    // changed, and so does its contract.
    expect(styles.borderWidth).toBe('0px');
    expect(styles.boxShadow).not.toBe('none');

    // Split on the commas between shadows rather than inside a colour function,
    // and drop the fully transparent ring layers Tailwind always emits.
    const layers = styles.boxShadow
      .split(/,(?![^(]*\))/)
      .map((layer) => layer.trim())
      .filter((layer) => layer !== '' && !/\/\s*0\)|rgba\(0,\s*0,\s*0,\s*0\)/.test(layer));

    // Two shadows, not one: a tight contact shadow under a wider ambient one is
    // what reads as an object above a surface. A single blur reads as fog.
    expect(layers.length).toBeGreaterThanOrEqual(2);

    // And tinted, which is the whole point of not using Tailwind's stock
    // shadows — those are untinted black at low opacity. Chrome serialises an
    // oklch colour as lab(), whose second and third components are the colour
    // axes: a true neutral leaves both at zero.
    const axes = /lab\(\s*[\d.-]+\s+([\d.-]+)\s+([\d.-]+)/.exec(layers[0] ?? '');

    expect(axes, `expected a tinted shadow, got ${layers[0]}`).not.toBeNull();
    expect(Math.abs(Number(axes?.[1])) + Math.abs(Number(axes?.[2]))).toBeGreaterThan(1);

    // Cards take radius + 4, so one operator-set value keeps a nested stack in
    // proportion rather than making every corner identical.
    expect(expected.radius).not.toBeNull();
    expect(styles.radius).toBe(`${(expected.radius ?? 0) + 4}px`);
  });

  test('honours a reduced-motion preference', async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();

    await page.goto(APP);

    // An unauthenticated visit lands on sign-in. Waiting for its control before
    // measuring is the point: page.evaluate does not wait for anything, so
    // without this the search below runs against whatever has rendered so far
    // and finds nothing.
    await expect(page.getByTestId('sign-in')).toBeVisible();

    const observed = await page.evaluate(() => {
      // A button that actually declares a transition. Taking the first in the
      // DOM measures whichever element happens to lead, which may declare none
      // at all — and then any assertion about duration passes for free.
      const button = [...document.querySelectorAll('button')].find((node) =>
        getComputedStyle(node).transitionProperty.includes('transform'),
      );

      if (button === undefined) {
        return null;
      }

      const style = getComputedStyle(button);

      return {
        // Colour still carries meaning, so its transition survives.
        duration: parseFloat(style.transitionDuration),
        properties: style.transitionProperty,
      };
    });

    expect(observed).not.toBeNull();

    // Gentler, not none. Switching every transition off is the easy reading of
    // this preference and the wrong one: what causes discomfort is movement, so
    // colour and opacity keep their timing and the transforms are neutralised.
    expect(observed!.duration).toBeGreaterThan(0);
    expect(observed!.properties).toContain('color');

    // Nothing travels: the press feedback does not shrink under reduced motion.
    // And the movement itself is gone: the rule neutralises the press transform
    // rather than switching the whole transition off.
    const travels = await page.evaluate(() =>
      [...document.styleSheets].some((sheet) => {
        try {
          return [...sheet.cssRules].some(
            (rule) =>
              rule.cssText.includes('prefers-reduced-motion') &&
              rule.cssText.includes('transform: none'),
          );
        } catch {
          return false;
        }
      }),
    );

    expect(travels).toBe(true);

    await context.close();
  });

  test('keeps press feedback short enough to feel immediate', async ({ page }) => {
    await page.goto(APP);

    // Located by name, not by tag: the overview's New link is an anchor styled
    // as a button, and a tag-bound locator broke the moment it stopped being a
    // <button>. A locator also waits for the render; a bare page.evaluate does
    // not, and measures whatever exists the instant it runs.
    const control = page.getByRole('link', { name: 'New link' });
    await expect(control).toBeVisible();

    const duration = await control.evaluate((node) => getComputedStyle(node).transitionDuration);

    // Bounded at both ends. An upper bound alone is satisfied by no transition
    // at all, which is how every duration in this interface sat at zero — the
    // token was written in Tailwind v3 syntax and silently resolved to nothing.
    expect(parseFloat(duration)).toBeGreaterThan(0);

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

    // Compared as whole family names, not substrings. Geist Mono's own fallback
    // chain names "Roboto Mono", which a substring check reads as Roboto and
    // fails on — an instance whose operator chose the mono face would look like
    // a violation of a rule it is keeping. The ban is on these families, not on
    // every family whose name contains one of them.
    const families = stack.split(',').map((family) => family.trim().replace(/^["']|["']$/g, ''));

    for (const banned of ['Inter', 'Roboto', 'Helvetica', 'Open Sans', 'Arial']) {
      expect(families).not.toContain(banned);
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

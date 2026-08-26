import { describe, expect, it } from 'vitest';

import {
  assessContrast,
  contrastRatio,
  deriveAccentStates,
  formatOklch,
  MIN_CONTRAST_LARGE,
  MIN_CONTRAST_TEXT,
  parseOklch,
  relativeLuminance,
} from '@/lib/color';

const WHITE = { l: 1, c: 0, h: 0 };
const BLACK = { l: 0, c: 0, h: 0 };
const LIGHT_CANVAS = { l: 0.985, c: 0.002, h: 90 };
const DARK_CANVAS = { l: 0.165, c: 0.003, h: 90 };

describe('parsing', () => {
  it('accepts the numeric form the API produces', () => {
    expect(parseOklch('oklch(0.55 0.16 250)')).toEqual({ l: 0.55, c: 0.16, h: 250 });
  });

  it('rejects anything else', () => {
    for (const value of [
      '#3366ff',
      'rgb(1 2 3)',
      'oklch(55% 0.16 250deg)',
      'oklch(0.55 0.16)',
      'oklch(2 0.16 250)',
      'oklch(0.5 0.16 400)',
      'javascript:alert(1)',
      '',
    ]) {
      expect(parseOklch(value), value).toBeNull();
    }
  });

  it('round-trips', () => {
    expect(formatOklch({ l: 0.5504, c: 0.1601, h: 250.04 })).toBe('oklch(0.55 0.16 250)');
  });
});

describe('luminance', () => {
  it('places white and black at the extremes', () => {
    expect(relativeLuminance(WHITE)).toBeCloseTo(1, 2);
    expect(relativeLuminance(BLACK)).toBeCloseTo(0, 3);
  });

  it('gives white against black the maximum ratio', () => {
    // WCAG's own bound; a conversion error shows up here first.
    expect(contrastRatio(WHITE, BLACK)).toBeCloseTo(21, 0);
  });

  it('is symmetric', () => {
    const a = { l: 0.55, c: 0.16, h: 250 };

    expect(contrastRatio(a, WHITE)).toBeCloseTo(contrastRatio(WHITE, a), 6);
  });
});

describe('contrast assessment', () => {
  it('passes a mid-dark accent on the light canvas', () => {
    const verdict = assessContrast({ l: 0.5, c: 0.16, h: 250 }, LIGHT_CANVAS);

    expect(verdict.passes).toBe(true);
    expect(verdict.suggestion).toBeNull();
  });

  it('fails a pale accent on the light canvas and suggests a darker one', () => {
    const pale = { l: 0.93, c: 0.06, h: 250 };
    const verdict = assessContrast(pale, LIGHT_CANVAS);

    expect(verdict.passes).toBe(false);
    expect(verdict.suggestion).not.toBeNull();
    // Only lightness moves: the operator's hue is preserved.
    expect(verdict.suggestion?.h).toBe(pale.h);
    expect(verdict.suggestion?.l).toBeLessThan(pale.l);
    expect(contrastRatio(verdict.suggestion!, LIGHT_CANVAS)).toBeGreaterThanOrEqual(
      MIN_CONTRAST_LARGE,
    );
  });

  it('fails a dark accent on the dark canvas and suggests a lighter one', () => {
    const dark = { l: 0.2, c: 0.1, h: 250 };
    const verdict = assessContrast(dark, DARK_CANVAS);

    expect(verdict.passes).toBe(false);
    expect(verdict.suggestion?.l).toBeGreaterThan(dark.l);
  });

  it('judges an accent against both canvases, not just one', () => {
    // A value that reads on light and vanishes on dark is exactly the case the
    // branding editor has to catch.
    const midtone = { l: 0.34, c: 0.14, h: 250 };

    expect(assessContrast(midtone, LIGHT_CANVAS).passes).toBe(true);
    expect(assessContrast(midtone, DARK_CANVAS).passes).toBe(false);
  });

  it('keeps body text readable on both canvases whatever the accent', () => {
    // Text colour never derives from the accent, which is why this holds.
    const lightInk = { l: 0.205, c: 0.004, h: 90 };
    const darkInk = { l: 0.955, c: 0.002, h: 90 };

    expect(contrastRatio(lightInk, LIGHT_CANVAS)).toBeGreaterThanOrEqual(MIN_CONTRAST_TEXT);
    expect(contrastRatio(darkInk, DARK_CANVAS)).toBeGreaterThanOrEqual(MIN_CONTRAST_TEXT);
  });
});

describe('derived accent states', () => {
  it('darkens on light and lightens on dark', () => {
    const accent = { l: 0.55, c: 0.16, h: 250 };

    expect(deriveAccentStates(accent, false).hover.l).toBeLessThan(accent.l);
    expect(deriveAccentStates(accent, true).hover.l).toBeGreaterThan(accent.l);
  });

  it('generates every state from one input, preserving the hue', () => {
    const accent = { l: 0.55, c: 0.16, h: 137 };
    const states = deriveAccentStates(accent, false);

    expect(Object.keys(states)).toEqual(['accent', 'hover', 'active', 'muted']);

    for (const state of Object.values(states)) {
      expect(state.h).toBe(137);
    }
  });

  it('moves active further than hover', () => {
    const accent = { l: 0.55, c: 0.16, h: 250 };
    const states = deriveAccentStates(accent, false);

    expect(states.active.l).toBeLessThan(states.hover.l);
  });

  it('mutes by dropping chroma, not by adding grey', () => {
    const accent = { l: 0.55, c: 0.16, h: 250 };

    expect(deriveAccentStates(accent, false).muted.c).toBeLessThan(accent.c);
    expect(deriveAccentStates(accent, true).muted.c).toBeLessThan(accent.c);
  });
});

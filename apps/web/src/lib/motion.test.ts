import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { DURATION, EASE, exitDuration, NEVER_ANIMATED, SPRING } from '@/lib/motion';

/**
 * The stylesheet is what actually drives the interface. Reading it here is what
 * stops this file from being a document that agrees with itself: without it,
 * every assertion below could pass while the shipped values drifted anywhere.
 */
const tokens = readFileSync(join(process.cwd(), 'src/app/globals.css'), 'utf8');

function cssToken(name: string): string {
  const match = tokens.match(new RegExp(`--${name}:\\s*([^;]+);`));

  if (match === null) {
    throw new Error(`globals.css declares no --${name}`);
  }

  return match[1]!.trim();
}

describe('motion contract', () => {
  it('keeps every interface duration under 300ms', () => {
    // Past that a dashboard feels like it is thinking rather than answering.
    for (const [surface, ms] of Object.entries(DURATION)) {
      expect(ms, surface).toBeLessThan(300);
    }
  });

  it('scales duration with how deliberate the surface is', () => {
    expect(DURATION.press).toBeLessThan(DURATION.tooltip);
    expect(DURATION.tooltip).toBeLessThan(DURATION.popover);
    expect(DURATION.popover).toBeLessThan(DURATION.sheet);
  });

  it('offers no ease-in curve', () => {
    // ease-in delays the first moment a viewer is watching, which reads as lag.
    for (const curve of Object.values(EASE)) {
      expect(curve.startsWith('cubic-bezier')).toBe(true);
    }

    expect(Object.keys(EASE)).toEqual(['out', 'inOut', 'drawer']);
  });

  it('exits faster than it enters', () => {
    // Waiting to see something is tolerable; waiting for something dismissed is not.
    expect(exitDuration(DURATION.sheet)).toBeLessThan(DURATION.sheet);
    expect(exitDuration(200)).toBe(140);
  });

  it('names the surfaces that must never animate', () => {
    expect(NEVER_ANIMATED).toContain('command-palette');
    expect(NEVER_ANIMATED).toContain('table-sort');
    expect(NEVER_ANIMATED).toContain('keyboard-navigation');
  });

  it('matches the durations the stylesheet actually applies', () => {
    // Two sources of truth for one value is how they drift apart.
    expect(cssToken('duration-press')).toBe(`${DURATION.press}ms`);
    expect(cssToken('duration-tooltip')).toBe(`${DURATION.tooltip}ms`);
    expect(cssToken('duration-popover')).toBe(`${DURATION.popover}ms`);
    expect(cssToken('duration-sheet')).toBe(`${DURATION.sheet}ms`);
  });

  it('matches the curves the stylesheet actually applies', () => {
    expect(cssToken('motion-ease-out')).toBe(EASE.out);
    expect(cssToken('motion-ease-in-out')).toBe(EASE.inOut);
    expect(cssToken('motion-ease-drawer')).toBe(EASE.drawer);
  });

  it('applies the faster exit it promises', () => {
    // The rule was asserted here long before anything used it, so the sheet
    // exited at entry speed while this file reported otherwise.
    expect(cssToken('duration-sheet-exit')).toBe(`${exitDuration(DURATION.sheet)}ms`);
  });

  it('keeps spring bounce subtle', () => {
    // Bounce reads as playful once and as unserious on the hundredth viewing.
    expect(SPRING.gentle.bounce).toBeLessThanOrEqual(0.15);
    expect(SPRING.playful.bounce).toBeLessThanOrEqual(0.3);
  });
});

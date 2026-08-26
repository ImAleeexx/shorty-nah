import { describe, expect, it } from 'vitest';

import { DURATION, EASE, exitDuration, NEVER_ANIMATED, SPRING } from '@/lib/motion';

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

  it('keeps spring bounce subtle', () => {
    // Bounce reads as playful once and as unserious on the hundredth viewing.
    expect(SPRING.gentle.bounce).toBeLessThanOrEqual(0.15);
    expect(SPRING.playful.bounce).toBeLessThanOrEqual(0.3);
  });
});

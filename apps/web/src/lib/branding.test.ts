import { describe, expect, it } from 'vitest';

import {
  DEFAULT_BRANDING,
  RADIUS_MAX,
  RADIUS_MIN,
  sanitiseBranding,
  type PublicConfiguration,
} from '@/lib/branding';

function config(branding: Partial<PublicConfiguration['branding']> = {}, name = 'Links') {
  return {
    installed: true,
    instance: { name },
    registration: { mode: 'closed' },
    branding: {
      accent: 'oklch(0.62 0.19 26)',
      radius: 12,
      typeface: 'geist',
      logo: null,
      wordmark: null,
      favicon: null,
      ...branding,
    },
  } satisfies PublicConfiguration;
}

describe('branding sanitiser', () => {
  it('falls back to defaults when the API is unreachable', () => {
    expect(sanitiseBranding(null)).toEqual(DEFAULT_BRANDING);
  });

  it('accepts a valid accent and radius', () => {
    const branding = sanitiseBranding(config());

    expect(branding.accent).toBe('oklch(0.62 0.19 26)');
    expect(branding.radius).toBe(12);
    expect(branding.name).toBe('Links');
  });

  it('replaces an accent it cannot recognise', () => {
    // This value is interpolated into a style attribute, so anything unexpected
    // must be replaced rather than passed through.
    for (const accent of [
      'red',
      '#ff0000',
      'expression(alert(1))',
      'oklch(0.6 0.2 20); background: url(x)',
      'var(--attacker)',
    ]) {
      expect(sanitiseBranding(config({ accent })).accent, accent).toBe(DEFAULT_BRANDING.accent);
    }
  });

  it('clamps the radius to the permitted range', () => {
    expect(sanitiseBranding(config({ radius: 0 })).radius).toBe(RADIUS_MIN);
    expect(sanitiseBranding(config({ radius: 9999 })).radius).toBe(RADIUS_MAX);
    expect(sanitiseBranding(config({ radius: 8 })).radius).toBe(8);
  });

  it('drops an asset path that tries to leave the instance', () => {
    for (const logo of [
      'https://attacker.example.org/logo.svg',
      '/storage/../../etc/passwd',
      '//attacker.example.org/logo.png',
      'javascript:alert(1)',
    ]) {
      expect(sanitiseBranding(config({ logo })).logo, logo).toBeNull();
    }
  });

  it('keeps an asset served by this instance', () => {
    expect(sanitiseBranding(config({ logo: '/storage/branding/logo.webp' })).logo).toBe(
      '/storage/branding/logo.webp',
    );
  });

  it('falls back when the instance has no name', () => {
    expect(sanitiseBranding(config({}, '')).name).toBe(DEFAULT_BRANDING.name);
  });
});

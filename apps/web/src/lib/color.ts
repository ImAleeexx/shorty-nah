/**
 * Colour maths for the branding editor.
 *
 * An operator picks one accent and every derived state is generated from it, so
 * the interface has to be able to answer whether that choice stays readable. That
 * means converting OKLCH to sRGB and computing a real contrast ratio, not
 * approximating.
 */

export type Oklch = { l: number; c: number; h: number };

export type AccentStates = {
  accent: Oklch;
  hover: Oklch;
  active: Oklch;
  muted: Oklch;
};

const OKLCH_PATTERN = /^oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\)$/;

export function parseOklch(value: string): Oklch | null {
  const match = OKLCH_PATTERN.exec(value.trim());

  if (match === null) {
    return null;
  }

  const l = Number(match[1]);
  const c = Number(match[2]);
  const h = Number(match[3]);

  if (!Number.isFinite(l) || !Number.isFinite(c) || !Number.isFinite(h)) {
    return null;
  }

  if (l < 0 || l > 1 || c < 0 || c > 0.5 || h < 0 || h > 360) {
    return null;
  }

  return { l, c, h };
}

export function formatOklch({ l, c, h }: Oklch): string {
  return `oklch(${round(l, 3)} ${round(c, 3)} ${round(h, 1)})`;
}

/**
 * OKLCH to linear sRGB, via OKLab.
 *
 * Matrices from Björn Ottosson's OKLab definition. The intermediate cube is
 * deliberate: OKLab's non-linearity is what makes a lightness shift look even
 * across hues, which is the property the accent's derived states rely on.
 */
function oklchToLinearSrgb({ l, c, h }: Oklch): [number, number, number] {
  const radians = (h * Math.PI) / 180;
  const a = c * Math.cos(radians);
  const b = c * Math.sin(radians);

  const lp = l + 0.3963377774 * a + 0.2158037573 * b;
  const mp = l - 0.1055613458 * a - 0.0638541728 * b;
  const sp = l - 0.0894841775 * a - 1.291485548 * b;

  const lc = lp * lp * lp;
  const mc = mp * mp * mp;
  const sc = sp * sp * sp;

  return [
    4.0767416621 * lc - 3.3077115913 * mc + 0.2309699292 * sc,
    -1.2684380046 * lc + 2.6097574011 * mc - 0.3413193965 * sc,
    -0.0041960863 * lc - 0.7034186147 * mc + 1.707614701 * sc,
  ];
}

/**
 * Relative luminance, per WCAG.
 *
 * Computed from linear sRGB before the transfer function, which is what the
 * specification means — applying it to gamma-encoded values is the usual mistake
 * and overstates contrast on dark colours.
 */
export function relativeLuminance(colour: Oklch): number {
  const [r, g, b] = oklchToLinearSrgb(colour).map((channel) => clamp(channel, 0, 1)) as [
    number,
    number,
    number,
  ];

  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrastRatio(a: Oklch, b: Oklch): number {
  const first = relativeLuminance(a);
  const second = relativeLuminance(b);

  const lighter = Math.max(first, second);
  const darker = Math.min(first, second);

  return (lighter + 0.05) / (darker + 0.05);
}

/** WCAG AA for body text. */
export const MIN_CONTRAST_TEXT = 4.5;

/** WCAG AA for large text and for the meaningful edge of a control. */
export const MIN_CONTRAST_LARGE = 3;

export type ContrastVerdict = {
  ratio: number;
  passes: boolean;
  suggestion: Oklch | null;
};

/**
 * Whether an accent is readable against a surface, and if not, the nearest
 * lightness that is.
 *
 * Suggesting rather than only warning matters: an operator who is told their
 * colour fails and left to guess will keep guessing. Only lightness moves, so the
 * hue they chose is preserved.
 */
export function assessContrast(
  accent: Oklch,
  surface: Oklch,
  minimum: number = MIN_CONTRAST_LARGE,
): ContrastVerdict {
  const ratio = contrastRatio(accent, surface);

  if (ratio >= minimum) {
    return { ratio, passes: true, suggestion: null };
  }

  // Move away from the surface: darken against a light background, lighten
  // against a dark one.
  const direction = relativeLuminance(surface) > 0.5 ? -1 : 1;

  for (let step = 1; step <= 40; step++) {
    const candidate: Oklch = {
      ...accent,
      l: clamp(accent.l + direction * step * 0.02, 0, 1),
    };

    if (contrastRatio(candidate, surface) >= minimum) {
      return { ratio, passes: false, suggestion: candidate };
    }
  }

  return { ratio, passes: false, suggestion: null };
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}

function round(value: number, places: number): number {
  const factor = 10 ** places;

  return Math.round(value * factor) / factor;
}

/**
 * The derived accent states, mirroring what the CSS computes.
 *
 * Duplicated here on purpose: the branding editor previews the full set before
 * anything is saved, and it cannot do that by reading computed styles for a value
 * the document has not been given yet.
 */
export function deriveAccentStates(accent: Oklch, dark: boolean): AccentStates {
  const shift = dark ? 1 : -1;

  return {
    accent,
    hover: { ...accent, l: clamp(accent.l + shift * (dark ? 0.06 : 0.05), 0, 1) },
    active: { ...accent, l: clamp(accent.l + shift * (dark ? 0.11 : 0.1), 0, 1) },
    muted: dark
      ? { ...accent, l: 0.28, c: accent.c * 0.4 }
      : { ...accent, l: 0.96, c: accent.c * 0.22 },
  };
}

/**
 * The branding the server resolves before the first byte of HTML.
 *
 * Fetched server-side and inlined into the document, so the interface never
 * paints with a default accent and then corrects itself.
 */
export type Branding = {
  name: string;
  accent: string;
  radius: number;
  typeface: string;
  logo: string | null;
  wordmark: string | null;
  favicon: string | null;
  footer: string;
};

export type PublicConfiguration = {
  installed: boolean;
  instance: { name: string | null };
  registration: { mode: string | null };
  branding: {
    accent: string | null;
    radius: number | null;
    typeface: string | null;
    logo: string | null;
    wordmark: string | null;
    favicon: string | null;
    // Optional: a client should still render against an instance whose API
    // predates this field rather than failing to parse the response.
    footer?: string | null;
  };
};

export const DEFAULT_BRANDING: Branding = {
  name: 'Shorty-Nah',
  accent: 'oklch(0.55 0.16 250)',
  radius: 10,
  typeface: 'geist',
  logo: null,
  wordmark: null,
  favicon: null,
  footer: '',
};

/** Bounds mirrored from the API so a hostile response cannot break the layout. */
export const RADIUS_MIN = 4;
export const RADIUS_MAX = 14;

const ACCENT_PATTERN = /^oklch\(\s*[\d.]+\s+[\d.]+\s+[\d.]+\s*\)$/;

/**
 * The faces this instance actually carries, and the stack each one resolves to.
 *
 * Mirrored from BrandingBounds. A selector offering a face the API refuses is
 * worse than no selector, and a face the API accepts but the document never
 * applies is only slightly better — the choice has to reach the rendered page.
 */
export const TYPEFACE_STACKS: Record<string, string> = {
  geist: 'var(--font-geist-sans), ui-sans-serif, system-ui, sans-serif',
  'geist-mono': 'var(--font-geist-mono), ui-monospace, monospace',
  'instrument-serif': 'var(--font-editorial), Georgia, ui-serif, serif',
};

export const DEFAULT_TYPEFACE = 'geist';

/** The stack for a chosen face, falling back rather than rendering nothing. */
export function typefaceStack(typeface: string | null): string {
  return TYPEFACE_STACKS[typeface ?? ''] ?? TYPEFACE_STACKS[DEFAULT_TYPEFACE]!;
}

/**
 * The API validates these already. Re-checking here is not redundant: this value
 * is interpolated into a style attribute, so anything unexpected must be replaced
 * rather than passed through.
 */
export function sanitiseBranding(config: PublicConfiguration | null): Branding {
  if (config === null) {
    return DEFAULT_BRANDING;
  }

  const accent = config.branding.accent;
  const radius = config.branding.radius;
  const typeface = config.branding.typeface ?? '';

  return {
    name: config.instance.name?.slice(0, 80) || DEFAULT_BRANDING.name,
    accent:
      typeof accent === 'string' && ACCENT_PATTERN.test(accent) ? accent : DEFAULT_BRANDING.accent,
    radius:
      typeof radius === 'number' && Number.isFinite(radius)
        ? Math.min(Math.max(Math.round(radius), RADIUS_MIN), RADIUS_MAX)
        : DEFAULT_BRANDING.radius,
    // Only a face this instance actually carries. An unknown one would resolve
    // to nothing and the page would render in the browser's default.
    typeface: typeface in TYPEFACE_STACKS ? typeface : DEFAULT_BRANDING.typeface,
    logo: assetPath(config.branding.logo),
    wordmark: assetPath(config.branding.wordmark),
    favicon: assetPath(config.branding.favicon),
    // Operator copy, so it is trimmed and bounded but otherwise left alone.
    footer: (config.branding.footer ?? '').slice(0, 200),
  };
}

/**
 * Branding assets are served from this instance. A path that tries to leave it is
 * dropped rather than rewritten.
 */
function assetPath(value: string | null): string | null {
  if (typeof value !== 'string' || value === '') {
    return null;
  }

  if (!value.startsWith('/storage/') || value.includes('..')) {
    return null;
  }

  return value;
}

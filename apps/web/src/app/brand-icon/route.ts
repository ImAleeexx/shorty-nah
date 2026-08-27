import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';

/**
 * The default browser-tab icon, drawn from the instance's own accent.
 *
 * This replaces the `favicon.ico` that shipped in `src/app`, which was the one
 * Next puts there when a project is created: an unbranded instance served the
 * framework's logo as its identity, and an instance that had uploaded a favicon
 * emitted two competing `<link rel="icon">` elements — the framework's first,
 * and whichever the browser preferred winning.
 *
 * An operator who uploads a favicon replaces this entirely. Until they do, the
 * tab still matches the accent they chose, which is the point of branding that
 * applies without a rebuild.
 *
 * Served from `/brand-icon` rather than `/icon.svg`: `icon` is a reserved
 * metadata filename in the app directory, so a route folder of that name is
 * shadowed by the convention and answers 404. A browser reads the content type,
 * not the extension.
 */
export const dynamic = 'force-dynamic';

export async function GET(): Promise<Response> {
  const branding = sanitiseBranding(await fetchPublicConfiguration());

  // Geometry only, no lettering: a glyph at sixteen pixels is a smudge, and this
  // mark has to survive being the size of a tab.
  const svg = [
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">',
    `<rect width="32" height="32" rx="7" fill="${branding.accent}"/>`,
    '<rect x="7" y="14.5" width="18" height="3" rx="1.5" fill="#ffffff"/>',
    '<rect x="7" y="9" width="11" height="3" rx="1.5" fill="#ffffff" opacity="0.55"/>',
    '<rect x="14" y="20" width="11" height="3" rx="1.5" fill="#ffffff" opacity="0.55"/>',
    '</svg>',
  ].join('');

  return new Response(svg, {
    headers: {
      'Content-Type': 'image/svg+xml',
      // The accent changes at runtime, so the icon must not outlive it.
      'Cache-Control': 'no-store',
    },
  });
}

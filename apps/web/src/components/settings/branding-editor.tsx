'use client';

import { useRouter } from 'next/navigation';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Warning } from '@/components/icons';
import { AssetField } from '@/components/settings/asset-field';
import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/field';
import { FormError } from '@/components/ui/form-error';
import { RADIUS_MAX, RADIUS_MIN, TYPEFACE_STACKS, typefaceStack } from '@/lib/branding';
import { apiRequest, type ApiFailure } from '@/lib/client-api';
import {
  assessContrast,
  formatOklch,
  parseOklch,
  MIN_CONTRAST_LARGE,
  type Oklch,
} from '@/lib/color';

/**
 * The light and dark surfaces the token layer defines. Hard-coded here because
 * the editor judges an accent against both modes at once, and only one of them
 * is ever the document's current mode.
 */
const SURFACES: { label: string; surface: Oklch; ink: Oklch }[] = [
  { label: 'Light', surface: { l: 1, c: 0, h: 0 }, ink: { l: 0.205, c: 0.004, h: 90 } },
  { label: 'Dark', surface: { l: 0.205, c: 0.003, h: 90 }, ink: { l: 0.955, c: 0.002, h: 90 } },
];

// Rendered from the shared map, not an invented list. The previous one offered
// inter-tight and satoshi, which this instance does not carry and the API
// refuses outright — selecting either produced a validation error.
const TYPEFACES = Object.keys(TYPEFACE_STACKS);

const TYPEFACE_LABELS: Record<string, string> = {
  geist: 'Geist',
  'geist-mono': 'Geist Mono',
  'instrument-serif': 'Instrument Serif',
};

export function BrandingEditor({
  initial,
  assets,
}: {
  initial: { name: string; accent: string; radius: number; typeface: string; footer: string };
  assets: { logo: string | null; wordmark: string | null; favicon: string | null };
}) {
  const router = useRouter();
  const [name, setName] = useState(initial.name);
  const [accent, setAccent] = useState(initial.accent);
  const [radius, setRadius] = useState(initial.radius);
  const [typeface, setTypeface] = useState(initial.typeface);
  const [footer, setFooter] = useState(initial.footer);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  const parsed = useMemo(() => parseOklch(accent), [accent]);

  const verdicts = useMemo(
    () =>
      parsed === null
        ? []
        : SURFACES.map((mode) => ({
            ...mode,
            verdict: assessContrast(parsed, mode.surface, MIN_CONTRAST_LARGE),
          })),
    [parsed],
  );

  const unreadable = verdicts.filter((entry) => !entry.verdict.passes);

  async function save() {
    setBusy(true);
    setFailure(null);

    const result = await apiRequest('/api/v1/branding', {
      method: 'PUT',
      body: { name, accent, radius, typeface, footer_text: footer },
    });

    setBusy(false);

    if (!result.ok) {
      setFailure(result);

      return;
    }

    toast.success('Branding saved', { id: 'branding-save' });
    router.refresh();
  }

  return (
    <div className="flex flex-col gap-5">
      <FormError failure={failure} />

      <Field label="Instance name" error={failure?.errors.name?.[0]}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            aria-describedby={describedBy}
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        )}
      </Field>

      <Field
        label="Accent"
        hint="An OKLCH colour. Every hover, active and muted state is derived from it."
        error={failure?.errors.accent?.[0]}
      >
        {({ id, describedBy }) => (
          <Input
            id={id}
            className="tabular"
            spellCheck={false}
            aria-describedby={describedBy}
            value={accent}
            onChange={(event) => setAccent(event.target.value)}
            data-testid="accent-input"
          />
        )}
      </Field>

      {/* Both modes at once: an accent is legible in one and invisible in the
          other often enough that judging only the current mode is a trap. */}
      <div className="flex flex-col gap-2" data-testid="branding-preview">
        {verdicts.map((entry) => (
          <div
            key={entry.label}
            className="border-border flex items-center justify-between gap-4 rounded-(--radius-token-sm) border px-3 py-2"
            style={{
              background: formatOklch(entry.surface),
              color: formatOklch(entry.ink),
              // The chosen face, so the selector shows its own effect rather
              // than only recording it.
              fontFamily: typefaceStack(typeface),
            }}
            data-mode={entry.label.toLowerCase()}
            data-passes={entry.verdict.passes ? 'true' : 'false'}
          >
            <span className="flex items-center gap-2 text-xs">
              <span
                className="inline-block size-4 rounded-[3px]"
                style={{ background: accent, borderRadius: `${radius / 3}px` }}
              />
              {entry.label}
            </span>
            <span className="tabular text-xs">{entry.verdict.ratio.toFixed(2)}:1</span>
          </div>
        ))}
      </div>

      {parsed === null ? (
        <p className="text-critical text-xs" role="alert">
          That is not an OKLCH colour. Try oklch(0.55 0.16 250).
        </p>
      ) : null}

      {unreadable.length > 0 ? (
        <div
          className="text-critical flex items-start gap-2 text-xs"
          role="alert"
          data-testid="contrast-warning"
        >
          <Warning size={15} className="mt-0.5 shrink-0" />
          <span>
            This accent falls below {MIN_CONTRAST_LARGE}:1 in{' '}
            {unreadable.map((entry) => entry.label.toLowerCase()).join(' and ')} mode.
            {unreadable[0]?.verdict.suggestion ? (
              <>
                {' '}
                <button
                  type="button"
                  className="underline underline-offset-2"
                  onClick={() => setAccent(formatOklch(unreadable[0]!.verdict.suggestion!))}
                  data-testid="apply-contrast-suggestion"
                >
                  Use {formatOklch(unreadable[0]!.verdict.suggestion!)}
                </button>{' '}
                instead.
              </>
            ) : null}
          </span>
        </div>
      ) : null}

      <Field label="Corner radius" error={failure?.errors.radius?.[0]}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            type="number"
            min={RADIUS_MIN}
            max={RADIUS_MAX}
            aria-describedby={describedBy}
            value={radius}
            onChange={(event) => setRadius(Number(event.target.value))}
          />
        )}
      </Field>

      <Field label="Typeface" error={failure?.errors.typeface?.[0]}>
        {({ id, describedBy }) => (
          <Select
            id={id}
            aria-describedby={describedBy}
            value={typeface}
            onChange={(event) => setTypeface(event.target.value)}
          >
            {TYPEFACES.map((face) => (
              <option key={face} value={face}>
                {TYPEFACE_LABELS[face] ?? face}
              </option>
            ))}
          </Select>
        )}
      </Field>

      <Field
        label="Footer"
        hint="Shown at the foot of every page. Leave it empty for no footer."
        error={failure?.errors.footer_text?.[0]}
      >
        {({ id, describedBy }) => (
          <Input
            id={id}
            aria-describedby={describedBy}
            maxLength={200}
            value={footer}
            onChange={(event) => setFooter(event.target.value)}
            data-testid="footer-input"
          />
        )}
      </Field>

      {/* Assets save on selection rather than with the form below: an upload is
          a request of its own, and holding a chosen file until someone presses
          a button that says "Save branding" is how a logo gets silently lost. */}
      <div className="border-border flex flex-col gap-5 border-t pt-5">
        <AssetField
          kind="logo"
          label="Logo"
          hint="Shown in the header when no wordmark is set. PNG, JPEG, WebP or GIF; SVG is refused, and the file is re-encoded before it is stored."
          current={assets.logo}
        />

        <AssetField
          kind="wordmark"
          label="Wordmark"
          hint="The horizontal lockup. Takes the header when present, in place of the logo and the instance name."
          current={assets.wordmark}
        />

        <AssetField
          kind="favicon"
          label="Favicon"
          hint="The browser tab icon. A square image reads best; anything else is letterboxed by the browser."
          current={assets.favicon}
        />
      </div>

      <div>
        <Button
          intent="primary"
          size="md"
          onClick={() => void save()}
          // The warning is not advisory: an unreadable accent cannot be saved,
          // because every derived state inherits the problem.
          disabled={busy || parsed === null || unreadable.length > 0}
          data-testid="save-branding"
        >
          {busy ? 'Saving' : 'Save branding'}
        </Button>
      </div>
    </div>
  );
}

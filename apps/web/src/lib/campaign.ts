/**
 * Campaign parameters, written onto the destination rather than stored beside it.
 *
 * The alternative — keeping them as their own fields and composing the final URL
 * at redirect time — was rejected in `design.md`: it puts string work on the hot
 * path for what is purely an authoring convenience, and it makes the stored
 * destination disagree with where visitors actually land, which is the field an
 * operator reads when auditing a link.
 *
 * The cost is that editing a value later means parsing it back out of the URL.
 * That is this module.
 */

/** The five parameters, in the order every analytics tool lists them. */
export const CAMPAIGN_KEYS = [
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'utm_term',
  'utm_content',
] as const;

export type CampaignKey = (typeof CAMPAIGN_KEYS)[number];

export type CampaignValues = Partial<Record<CampaignKey, string>>;

export type CampaignPreset = {
  name: string;
  values: CampaignValues;
};

/**
 * Reads the campaign parameters already on a destination.
 *
 * Anything that is not a campaign parameter is ignored here and preserved by
 * `applyCampaign` — a destination's own query string is none of this feature's
 * business.
 */
export function readCampaign(destination: string): CampaignValues {
  const url = parse(destination);

  if (url === null) {
    return {};
  }

  const values: CampaignValues = {};

  for (const key of CAMPAIGN_KEYS) {
    const value = url.searchParams.get(key);

    if (value !== null && value !== '') {
      values[key] = value;
    }
  }

  return values;
}

/**
 * Writes campaign parameters onto a destination.
 *
 * An empty value removes the parameter rather than writing an empty one, so
 * clearing a field in the builder clears it in the URL. Existing non-campaign
 * parameters keep their place and their order.
 */
export function applyCampaign(destination: string, values: CampaignValues): string {
  const url = parse(destination);

  if (url === null) {
    return destination;
  }

  for (const key of CAMPAIGN_KEYS) {
    const value = values[key]?.trim() ?? '';

    if (value === '') {
      url.searchParams.delete(key);
    } else {
      // set, not append: editing a parameter that is already there has to
      // replace it, and append would leave the old value in place and the new
      // one after it.
      url.searchParams.set(key, value);
    }
  }

  return url.toString();
}

/** Whether a destination carries any campaign parameter at all. */
export function hasCampaign(destination: string): boolean {
  return Object.keys(readCampaign(destination)).length > 0;
}

/**
 * Presets come from a settings string. A malformed one yields no presets rather
 * than breaking the link form: an operator who pasted something wrong into
 * settings should still be able to create a link.
 */
export function parsePresets(raw: unknown): CampaignPreset[] {
  if (typeof raw !== 'string' || raw.trim() === '') {
    return [];
  }

  let parsed: unknown;

  try {
    parsed = JSON.parse(raw);
  } catch {
    return [];
  }

  if (!Array.isArray(parsed)) {
    return [];
  }

  const presets: CampaignPreset[] = [];

  for (const entry of parsed) {
    if (typeof entry !== 'object' || entry === null) {
      continue;
    }

    const candidate = entry as { name?: unknown; values?: unknown };

    if (typeof candidate.name !== 'string' || candidate.name.trim() === '') {
      continue;
    }

    const values: CampaignValues = {};

    if (typeof candidate.values === 'object' && candidate.values !== null) {
      for (const key of CAMPAIGN_KEYS) {
        const value = (candidate.values as Record<string, unknown>)[key];

        if (typeof value === 'string' && value.trim() !== '') {
          values[key] = value;
        }
      }
    }

    presets.push({ name: candidate.name, values });
  }

  return presets;
}

function parse(destination: string): URL | null {
  try {
    return new URL(destination);
  } catch {
    return null;
  }
}

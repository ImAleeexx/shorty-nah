'use client';

import { useMemo } from 'react';

import { Field, Input, Select } from '@/components/ui/field';
import {
  applyCampaign,
  CAMPAIGN_KEYS,
  readCampaign,
  type CampaignKey,
  type CampaignPreset,
} from '@/lib/campaign';

const LABELS: Record<CampaignKey, string> = {
  utm_source: 'Source',
  utm_medium: 'Medium',
  utm_campaign: 'Campaign',
  utm_term: 'Term',
  utm_content: 'Content',
};

const HINTS: Partial<Record<CampaignKey, string>> = {
  utm_source: 'Where the traffic comes from — newsletter, partner, print.',
  utm_medium: 'How it arrives — email, social, qr.',
};

/**
 * Campaign parameters, edited against the destination itself.
 *
 * There is no state of its own: the values are read out of the destination on
 * every render and written back into it on every change. That is what keeps the
 * destination field and this panel from disagreeing — the URL is the single
 * copy, and this is a view onto it.
 */
export function CampaignFields({
  destination,
  onDestinationChange,
  presets,
}: {
  destination: string;
  onDestinationChange: (destination: string) => void;
  presets: CampaignPreset[];
}) {
  const values = useMemo(() => readCampaign(destination), [destination]);

  function set(key: CampaignKey, value: string) {
    onDestinationChange(applyCampaign(destination, { ...values, [key]: value }));
  }

  return (
    <div className="flex flex-col gap-4" data-testid="campaign-fields">
      {presets.length > 0 ? (
        <Field label="Preset" hint="Fills the fields below. Every value stays editable.">
          {({ id, describedBy }) => (
            <Select
              id={id}
              aria-describedby={describedBy}
              value=""
              data-testid="campaign-preset"
              onChange={(event) => {
                const preset = presets.find((entry) => entry.name === event.target.value);

                if (preset !== undefined) {
                  onDestinationChange(applyCampaign(destination, { ...values, ...preset.values }));
                }
              }}
            >
              <option value="">Choose a preset</option>
              {presets.map((preset) => (
                <option key={preset.name} value={preset.name}>
                  {preset.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
      ) : null}

      {CAMPAIGN_KEYS.map((key) => (
        <Field key={key} label={LABELS[key]} hint={HINTS[key]}>
          {({ id, describedBy }) => (
            <Input
              id={id}
              aria-describedby={describedBy}
              spellCheck={false}
              value={values[key] ?? ''}
              data-testid={`campaign-${key}`}
              onChange={(event) => set(key, event.target.value)}
            />
          )}
        </Field>
      ))}
    </div>
  );
}

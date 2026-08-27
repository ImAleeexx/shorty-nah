'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Select } from '@/components/ui/field';
import { FormError } from '@/components/ui/form-error';
import { apiRequest, type ApiFailure } from '@/lib/client-api';

export type SettingsValues = Record<string, string | number | boolean | null>;

type FieldSpec = {
  key: string;
  label: string;
  hint?: string;
  kind: 'text' | 'number' | 'password' | 'email' | 'boolean' | 'choice';
  choices?: { value: string; label: string }[];
};

/**
 * One group of runtime settings.
 *
 * Every group posts to the same endpoint, which validates against the registry —
 * so a group here is a presentation decision, not a second source of truth about
 * what may be written.
 */
export function SettingsForm({
  title,
  fields,
  values,
  testId,
}: {
  title: string;
  fields: FieldSpec[];
  values: SettingsValues;
  testId: string;
}) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);
    const settings: SettingsValues = {};

    for (const field of fields) {
      const raw = data.get(field.key);

      if (field.kind === 'boolean') {
        settings[field.key] = raw === 'on';

        continue;
      }

      const text = typeof raw === 'string' ? raw.trim() : '';

      if (field.kind === 'password' && text === '') {
        // Never sent back, so an empty box means "leave it alone" rather than
        // "clear it".
        continue;
      }

      if (field.kind === 'number') {
        settings[field.key] = text === '' ? null : Number(text);

        continue;
      }

      settings[field.key] = text === '' ? null : text;
    }

    setBusy(true);
    setFailure(null);

    const result = await apiRequest('/api/v1/settings', { method: 'PUT', body: { settings } });

    setBusy(false);

    if (!result.ok) {
      setFailure(result);

      return;
    }

    toast.success(`${title} saved`, { id: `settings-${testId}` });
    router.refresh();
  }

  return (
    <form className="flex flex-col gap-5" onSubmit={submit} data-testid={testId}>
      <FormError failure={failure} />

      {fields.map((field) => {
        const error = failure?.errors[`settings.${field.key}`]?.[0];
        const value = values[field.key];

        if (field.kind === 'boolean') {
          return (
            <Checkbox
              key={field.key}
              name={field.key}
              label={field.label}
              description={field.hint}
              defaultChecked={value === true}
            />
          );
        }

        return (
          <Field key={field.key} label={field.label} hint={field.hint} error={error}>
            {({ id, describedBy }) =>
              field.kind === 'choice' ? (
                <Select
                  id={id}
                  name={field.key}
                  aria-describedby={describedBy}
                  defaultValue={typeof value === 'string' ? value : ''}
                >
                  {(field.choices ?? []).map((choice) => (
                    <option key={choice.value} value={choice.value}>
                      {choice.label}
                    </option>
                  ))}
                </Select>
              ) : (
                <Input
                  id={id}
                  name={field.key}
                  type={field.kind === 'number' ? 'number' : field.kind}
                  aria-describedby={describedBy}
                  autoComplete={field.kind === 'password' ? 'off' : undefined}
                  placeholder={
                    field.kind === 'password' && value === true ? 'Configured' : undefined
                  }
                  defaultValue={
                    field.kind === 'password'
                      ? ''
                      : value === null || value === undefined || value === true
                        ? ''
                        : String(value)
                  }
                />
              )
            }
          </Field>
        );
      })}

      <div>
        <Button intent="primary" size="md" type="submit" disabled={busy}>
          {busy ? 'Saving' : `Save ${title.toLowerCase()}`}
        </Button>
      </div>
    </form>
  );
}

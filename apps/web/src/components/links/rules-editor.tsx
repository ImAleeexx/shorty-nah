'use client';

import { useState } from 'react';
import { toast } from 'sonner';

import { CaretDown, Plus, Trash } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/field';
import { FormError } from '@/components/ui/form-error';
import { apiRequest, type ApiFailure } from '@/lib/client-api';

export type RoutingRule = {
  kind: 'country' | 'device' | 'language' | 'time_window';
  value: string;
  destination: string;
};

const KINDS: { value: RoutingRule['kind']; label: string; hint: string; placeholder: string }[] = [
  {
    value: 'country',
    label: 'Country',
    hint: 'Two-letter codes, separated by commas.',
    placeholder: 'ES, PT',
  },
  { value: 'device', label: 'Device', hint: 'mobile, tablet or desktop.', placeholder: 'mobile' },
  { value: 'language', label: 'Language', hint: 'A tag such as es or es-419.', placeholder: 'es' },
  {
    value: 'time_window',
    label: 'Time window',
    hint: 'HH:MM-HH:MM in the reporting timezone. A window may cross midnight.',
    placeholder: '09:00-17:00',
  },
];

/**
 * A link's routing rules, in the order they are evaluated.
 *
 * Ordering is the semantics — first match wins — so the list is written as a set
 * and the move controls are plain buttons rather than a drag surface. A drag
 * that needs a pointer excludes a keyboard, and this list is short enough that
 * two buttons are faster anyway.
 */
export function RulesEditor({ linkId, initial }: { linkId: string; initial: RoutingRule[] }) {
  const [rules, setRules] = useState<RoutingRule[]>(initial);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  function update(index: number, patch: Partial<RoutingRule>) {
    setRules((current) =>
      current.map((rule, position) => (position === index ? { ...rule, ...patch } : rule)),
    );
  }

  function move(index: number, delta: number) {
    setRules((current) => {
      const next = [...current];
      const target = index + delta;

      if (target < 0 || target >= next.length) {
        return current;
      }

      [next[index], next[target]] = [next[target]!, next[index]!];

      return next;
    });
  }

  async function save() {
    setBusy(true);
    setFailure(null);

    const result = await apiRequest(`/api/v1/links/${linkId}/rules`, {
      method: 'PUT',
      body: { rules },
    });

    setBusy(false);

    if (!result.ok) {
      setFailure(result);

      return;
    }

    toast.success('Routing saved', { id: 'rules-save' });
  }

  return (
    <div className="flex flex-col gap-4" data-testid="rules-editor">
      <FormError failure={failure} />

      {failure?.errors.rules?.[0] !== undefined ? (
        <p className="text-critical text-sm" role="alert">
          {failure.errors.rules[0]}
        </p>
      ) : null}

      {rules.length === 0 ? (
        <p className="text-ink-muted text-sm">
          No rules. Everyone reaches the link&rsquo;s own destination.
        </p>
      ) : (
        <ol className="flex flex-col gap-4">
          {rules.map((rule, index) => {
            const kind = KINDS.find((entry) => entry.value === rule.kind) ?? KINDS[0]!;

            return (
              <li
                key={index}
                className="border-border rounded-(--radius-token) border p-3"
                data-testid="rule-row"
              >
                <div className="mb-3 flex items-center justify-between gap-2">
                  <span className="text-ink-muted tabular text-xs">{index + 1}</span>

                  <div className="flex items-center gap-1">
                    <Button
                      type="button"
                      intent="ghost"
                      size="sm"
                      disabled={index === 0}
                      onClick={() => move(index, -1)}
                      aria-label={`Move rule ${index + 1} earlier`}
                      data-testid={`rule-up-${index}`}
                    >
                      <CaretDown size={14} className="rotate-180" />
                    </Button>

                    <Button
                      type="button"
                      intent="ghost"
                      size="sm"
                      disabled={index === rules.length - 1}
                      onClick={() => move(index, 1)}
                      aria-label={`Move rule ${index + 1} later`}
                      data-testid={`rule-down-${index}`}
                    >
                      <CaretDown size={14} />
                    </Button>

                    <Button
                      type="button"
                      intent="ghost"
                      size="sm"
                      onClick={() => setRules((current) => current.filter((_, p) => p !== index))}
                      aria-label={`Remove rule ${index + 1}`}
                      data-testid={`rule-remove-${index}`}
                    >
                      <Trash size={14} />
                    </Button>
                  </div>
                </div>

                <div className="flex flex-col gap-3">
                  <Field label="Matches on">
                    {({ id }) => (
                      <Select
                        id={id}
                        value={rule.kind}
                        data-testid={`rule-kind-${index}`}
                        onChange={(event) =>
                          update(index, {
                            kind: event.target.value as RoutingRule['kind'],
                            value: '',
                          })
                        }
                      >
                        {KINDS.map((entry) => (
                          <option key={entry.value} value={entry.value}>
                            {entry.label}
                          </option>
                        ))}
                      </Select>
                    )}
                  </Field>

                  <Field label="Value" hint={kind.hint}>
                    {({ id, describedBy }) => (
                      <Input
                        id={id}
                        aria-describedby={describedBy}
                        className="tabular"
                        spellCheck={false}
                        placeholder={kind.placeholder}
                        value={rule.value}
                        data-testid={`rule-value-${index}`}
                        onChange={(event) => update(index, { value: event.target.value })}
                      />
                    )}
                  </Field>

                  <Field label="Sends them to">
                    {({ id, describedBy }) => (
                      <Input
                        id={id}
                        type="url"
                        inputMode="url"
                        aria-describedby={describedBy}
                        spellCheck={false}
                        value={rule.destination}
                        data-testid={`rule-destination-${index}`}
                        onChange={(event) => update(index, { destination: event.target.value })}
                      />
                    )}
                  </Field>
                </div>
              </li>
            );
          })}
        </ol>
      )}

      <div className="flex items-center gap-2">
        <Button
          type="button"
          intent="outline"
          size="sm"
          data-testid="add-rule"
          onClick={() =>
            setRules((current) => [...current, { kind: 'country', value: '', destination: '' }])
          }
        >
          <Plus size={14} />
          Add a rule
        </Button>

        <Button
          type="button"
          intent="primary"
          size="sm"
          disabled={busy}
          onClick={() => void save()}
          data-testid="save-rules"
        >
          {busy ? 'Saving' : 'Save routing'}
        </Button>
      </div>
    </div>
  );
}

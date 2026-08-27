'use client';

import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';

import { ArrowsClockwise, Check, LockKey, Prohibit, Warning } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Select } from '@/components/ui/field';
import { RADIUS_MAX, RADIUS_MIN } from '@/lib/branding';
import {
  checkConnectivity,
  completeInstallation,
  fetchState,
  forgetToken,
  readStoredToken,
  storeToken,
  submitStep,
  verifyToken,
  type DependencyStatus,
  type SetupFailure,
  type SetupState,
  type SetupStep,
} from '@/lib/setup';

const STEP_TITLES: Record<SetupStep, { title: string; description: string }> = {
  connectivity: {
    title: 'Dependencies',
    description: 'Every datastore this instance needs must answer before anything is configured.',
  },
  administrator: {
    title: 'Your account',
    description: 'The first account owns this instance and cannot be locked out by another.',
  },
  instance: {
    title: 'Identity',
    description: 'What this instance is called, and the domain your short links live on.',
  },
  branding: {
    title: 'Appearance',
    description: 'Applied at runtime. Changing it later never needs a rebuild.',
  },
  analytics: {
    title: 'Analytics',
    description: 'How long raw events are kept, and what counts as a real visitor.',
  },
  registration: {
    title: 'Access',
    description: 'Who, if anyone, may create an account without being invited.',
  },
  mail: {
    title: 'Outbound mail',
    description: 'Needed for invitations and password resets. You can skip this.',
  },
};

const STEP_ORDER: SetupStep[] = [
  'connectivity',
  'administrator',
  'instance',
  'branding',
  'analytics',
  'registration',
  'mail',
];

const TYPEFACES = ['geist', 'inter-tight', 'satoshi'] as const;

type Phase = 'loading' | 'gate' | 'wizard' | 'installed';

export function SetupWizard({ instanceName }: { instanceName: string }) {
  const router = useRouter();
  const [phase, setPhase] = useState<Phase>('loading');
  const [token, setToken] = useState('');
  const [tokenInput, setTokenInput] = useState('');
  const [state, setState] = useState<SetupState | null>(null);
  const [current, setCurrent] = useState<SetupStep>('connectivity');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [dependencies, setDependencies] = useState<DependencyStatus[]>([]);

  const applyFailure = useCallback((failure: SetupFailure) => {
    setErrors(failure.errors);
    setMessage(failure.message);

    if (failure.status === 401) {
      // The token stopped working: send the operator back to the gate rather
      // than leaving them typing into a form nothing will accept.
      forgetToken();
      setToken('');
      setPhase('gate');
    }
  }, []);

  const loadState = useCallback(
    async (candidate: string) => {
      const result = await fetchState(candidate);

      if (!result.ok) {
        applyFailure(result);

        return false;
      }

      setState(result.data);
      setCurrent(result.data.next ?? 'mail');
      setPhase('wizard');
      setMessage(null);
      setErrors({});

      return true;
    },
    [applyFailure],
  );

  // Resumption happens off the render path: the stored token is read and
  // exchanged for progress asynchronously, so no state is set while the effect
  // body is still running.
  useEffect(() => {
    let cancelled = false;

    void (async () => {
      const stored = readStoredToken();

      if (stored === '') {
        if (!cancelled) {
          setPhase('gate');
        }

        return;
      }

      if (cancelled) {
        return;
      }

      setToken(stored);

      const resumed = await loadState(stored);

      if (!resumed && !cancelled) {
        setPhase('gate');
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [loadState]);

  async function submitToken(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setMessage(null);

    const candidate = tokenInput.trim();
    const result = await verifyToken(candidate);

    if (!result.ok) {
      setBusy(false);
      setMessage(
        result.status === 401
          ? 'That token was not accepted. It is printed in the container log and written to the host.'
          : result.message,
      );

      return;
    }

    storeToken(candidate);
    setToken(candidate);
    setTokenInput('');
    await loadState(candidate);
    setBusy(false);
  }

  async function runConnectivity() {
    setBusy(true);
    setMessage(null);

    const result = await checkConnectivity(token);

    setBusy(false);

    if (!result.ok) {
      applyFailure(result);

      return;
    }

    setDependencies(result.data.dependencies);

    if (!result.data.healthy) {
      setMessage('Fix the dependency below, then check again.');
    }
  }

  async function send(step: Exclude<SetupStep, 'connectivity'>, body: Record<string, unknown>) {
    setBusy(true);
    setMessage(null);
    setErrors({});

    const result = await submitStep(step, token, body);

    setBusy(false);

    if (!result.ok) {
      applyFailure(result);

      return;
    }

    await loadState(token);
  }

  async function finish() {
    setBusy(true);
    setMessage(null);

    const result = await completeInstallation(token);

    setBusy(false);

    if (!result.ok) {
      applyFailure(result);

      return;
    }

    forgetToken();
    setPhase('installed');
  }

  if (phase === 'loading') {
    return (
      <p className="text-ink-muted text-sm" role="status">
        Checking this instance.
      </p>
    );
  }

  if (phase === 'installed') {
    return (
      <div className="setup-step flex flex-col gap-6" data-testid="setup-complete">
        <Heading>Installed</Heading>
        <p className="text-ink-muted text-sm">
          {instanceName} is ready and you are signed in as the owner. Setup is now closed
          permanently.
        </p>
        <div>
          <Button
            intent="primary"
            size="lg"
            onClick={() => {
              // refresh() first: the dashboard is a server component whose
              // cached render still believes this instance is uninstalled.
              router.refresh();
              router.push('/');
            }}
          >
            Open the dashboard
          </Button>
        </div>
      </div>
    );
  }

  if (phase === 'gate') {
    return (
      <form
        className="setup-step flex flex-col gap-6"
        onSubmit={submitToken}
        data-testid="setup-gate"
      >
        <div className="flex flex-col gap-2">
          <Heading>Claim this instance</Heading>
          <p className="text-ink-muted text-sm">
            A setup token was generated on first boot. It is printed in the container log and
            written to a file on the host. Nothing is configured until it is presented.
          </p>
        </div>

        <Field
          label="Setup token"
          hint="Run `make setup-token`, or read it from the api container's log."
          error={message ?? undefined}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              aria-describedby={describedBy}
              className="tabular"
              autoComplete="off"
              spellCheck={false}
              value={tokenInput}
              onChange={(event) => setTokenInput(event.target.value)}
              data-testid="setup-token-input"
            />
          )}
        </Field>

        <div>
          <Button
            intent="primary"
            size="lg"
            type="submit"
            disabled={busy || tokenInput.trim() === ''}
          >
            <LockKey size={16} />
            {busy ? 'Checking' : 'Continue'}
          </Button>
        </div>
      </form>
    );
  }

  return (
    <div className="flex flex-col gap-8">
      <Progress steps={STEP_ORDER} state={state} current={current} />

      <div key={current} className="setup-step flex flex-col gap-6">
        <div className="flex flex-col gap-2">
          <Heading>{STEP_TITLES[current].title}</Heading>
          <p className="text-ink-muted text-sm">{STEP_TITLES[current].description}</p>
        </div>

        {message ? (
          <p className="text-critical flex items-start gap-2 text-sm" role="alert">
            <Warning size={16} className="mt-0.5 shrink-0" />
            {message}
          </p>
        ) : null}

        <StepBody
          step={current}
          state={state}
          busy={busy}
          errors={errors}
          dependencies={dependencies}
          onConnectivity={runConnectivity}
          onAdvance={() => loadState(token)}
          onSubmit={send}
          onFinish={finish}
        />
      </div>
    </div>
  );
}

function Heading({ children }: { children: React.ReactNode }) {
  // The editorial serif is reserved for setup and empty states; this is one of
  // the two places it is allowed to carry a page.
  return <h1 className="text-ink font-serif text-3xl tracking-tight">{children}</h1>;
}

function Progress({
  steps,
  state,
  current,
}: {
  steps: SetupStep[];
  state: SetupState | null;
  current: SetupStep;
}) {
  return (
    <ol className="flex flex-wrap gap-x-4 gap-y-2" data-testid="setup-progress">
      {steps.map((step) => {
        const complete = state?.steps.find((entry) => entry.step === step)?.complete ?? false;
        const active = step === current;

        return (
          <li
            key={step}
            className="text-ink-subtle flex items-center gap-1.5 text-xs"
            data-step={step}
            data-complete={complete ? 'true' : 'false'}
            aria-current={active ? 'step' : undefined}
          >
            {complete ? (
              <Check size={13} className="text-accent" />
            ) : (
              <span
                className={
                  active
                    ? 'bg-accent inline-block size-1.5 rounded-[2px]'
                    : 'bg-border-strong inline-block size-1.5 rounded-[2px]'
                }
              />
            )}
            <span className={active ? 'text-ink font-medium' : undefined}>
              {STEP_TITLES[step].title}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

function first(errors: Record<string, string[]>, field: string): string | undefined {
  return errors[field]?.[0];
}

function StepBody({
  step,
  state,
  busy,
  errors,
  dependencies,
  onConnectivity,
  onAdvance,
  onSubmit,
  onFinish,
}: {
  step: SetupStep;
  state: SetupState | null;
  busy: boolean;
  errors: Record<string, string[]>;
  dependencies: DependencyStatus[];
  onConnectivity: () => Promise<void>;
  onAdvance: () => Promise<boolean>;
  onSubmit: (
    step: Exclude<SetupStep, 'connectivity'>,
    body: Record<string, unknown>,
  ) => Promise<void>;
  onFinish: () => Promise<void>;
}) {
  const values = state?.values;

  if (step === 'connectivity') {
    const allHealthy =
      dependencies.length > 0 && dependencies.every((dependency) => dependency.healthy);

    return (
      <div className="flex flex-col gap-5">
        {dependencies.length > 0 ? (
          <ul className="border-border divide-border divide-y rounded-(--radius-token) border">
            {dependencies.map((dependency) => (
              <li
                key={dependency.name}
                className="flex items-start justify-between gap-4 px-4 py-3"
                data-testid={`dependency-${dependency.name}`}
                data-healthy={dependency.healthy ? 'true' : 'false'}
              >
                <span className="text-ink text-sm">{dependency.name}</span>
                <span className="flex items-center gap-1.5 text-xs">
                  {dependency.healthy ? (
                    <>
                      <Check size={14} className="text-accent" />
                      <span className="text-ink-muted">Reachable</span>
                    </>
                  ) : (
                    <>
                      <Prohibit size={14} className="text-critical" />
                      <span className="text-critical">{dependency.reason}</span>
                    </>
                  )}
                </span>
              </li>
            ))}
          </ul>
        ) : null}

        <div className="flex flex-wrap items-center gap-3">
          {/* The result is shown before the wizard moves on: an operator who
              never sees the check has no reason to trust it ran. */}
          {allHealthy ? (
            <Button
              intent="primary"
              size="lg"
              onClick={() => void onAdvance()}
              disabled={busy}
              data-testid="continue-from-dependencies"
            >
              Continue
            </Button>
          ) : null}

          <Button
            intent={allHealthy ? 'ghost' : 'primary'}
            size="lg"
            onClick={() => void onConnectivity()}
            disabled={busy}
            data-testid="check-dependencies"
          >
            <ArrowsClockwise size={16} />
            {busy ? 'Checking' : dependencies.length > 0 ? 'Check again' : 'Check dependencies'}
          </Button>
        </div>
      </div>
    );
  }

  if (step === 'administrator') {
    return (
      <StepForm
        busy={busy}
        submitLabel="Create account"
        onSubmit={(data) =>
          onSubmit('administrator', {
            name: data.get('name'),
            email: data.get('email'),
            password: data.get('password'),
          })
        }
      >
        <Field label="Name" error={first(errors, 'name')}>
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="name"
              aria-describedby={describedBy}
              autoComplete="name"
              required
            />
          )}
        </Field>
        <Field label="Email" error={first(errors, 'email')}>
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="email"
              type="email"
              aria-describedby={describedBy}
              autoComplete="email"
              required
            />
          )}
        </Field>
        <Field
          label="Password"
          hint="Long beats complicated. A passphrase is the easiest way to reach the length."
          error={first(errors, 'password')}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="password"
              type="password"
              aria-describedby={describedBy}
              autoComplete="new-password"
              required
            />
          )}
        </Field>
      </StepForm>
    );
  }

  if (step === 'instance') {
    return (
      <StepForm
        busy={busy}
        submitLabel="Save identity"
        onSubmit={(data) =>
          onSubmit('instance', { name: data.get('name'), domain: data.get('domain') })
        }
      >
        <Field label="Instance name" error={first(errors, 'name')}>
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="name"
              aria-describedby={describedBy}
              defaultValue={values?.instance_name ?? ''}
              required
            />
          )}
        </Field>
        <Field
          label="Primary short domain"
          hint="The hostname your short links are served from. Verify its DNS afterwards."
          error={first(errors, 'domain')}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="domain"
              aria-describedby={describedBy}
              defaultValue={values?.domain ?? ''}
              autoCapitalize="none"
              spellCheck={false}
              required
            />
          )}
        </Field>
      </StepForm>
    );
  }

  if (step === 'branding') {
    return (
      <StepForm
        busy={busy}
        submitLabel="Save appearance"
        onSubmit={(data) =>
          onSubmit('branding', {
            accent: data.get('accent'),
            radius: Number(data.get('radius')),
            typeface: data.get('typeface'),
          })
        }
      >
        <Field
          label="Accent"
          hint="An OKLCH colour. Every derived state is computed from it."
          error={first(errors, 'accent')}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="accent"
              className="tabular"
              aria-describedby={describedBy}
              defaultValue={values?.accent ?? 'oklch(0.55 0.16 250)'}
              spellCheck={false}
            />
          )}
        </Field>
        <Field label="Corner radius" error={first(errors, 'radius')}>
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="radius"
              type="number"
              min={RADIUS_MIN}
              max={RADIUS_MAX}
              aria-describedby={describedBy}
              defaultValue={values?.radius ?? 10}
            />
          )}
        </Field>
        <Field label="Typeface" error={first(errors, 'typeface')}>
          {({ id, describedBy }) => (
            <Select
              id={id}
              name="typeface"
              aria-describedby={describedBy}
              defaultValue={values?.typeface ?? 'geist'}
            >
              {TYPEFACES.map((face) => (
                <option key={face} value={face}>
                  {face}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </StepForm>
    );
  }

  if (step === 'analytics') {
    return (
      <StepForm
        busy={busy}
        submitLabel="Save analytics"
        onSubmit={(data) =>
          onSubmit('analytics', {
            retention_days: Number(data.get('retention_days')),
            bot_filtering: data.get('bot_filtering') === 'on',
            maxmind_account_id: data.get('maxmind_account_id') || null,
            maxmind_license_key: data.get('maxmind_license_key') || null,
          })
        }
      >
        <Field
          label="Retention"
          hint="Days of raw events. Rollups are never expired, so reports survive it."
          error={first(errors, 'retention_days')}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="retention_days"
              type="number"
              min={1}
              max={3650}
              aria-describedby={describedBy}
              defaultValue={values?.retention_days ?? 365}
            />
          )}
        </Field>

        <Checkbox
          name="bot_filtering"
          label="Exclude bots and prefetches"
          description="Counts what a person did, not what a crawler fetched."
          defaultChecked={values?.bot_filtering ?? true}
        />

        <Field
          label="MaxMind account ID"
          hint="Optional, and kept here for reference only. The updater that downloads the databases reads MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY from the environment, because it runs before this instance exists."
          error={first(errors, 'maxmind_account_id')}
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="maxmind_account_id"
              aria-describedby={describedBy}
              autoComplete="off"
            />
          )}
        </Field>
        <Field label="MaxMind licence key" error={first(errors, 'maxmind_license_key')}>
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="maxmind_license_key"
              type="password"
              aria-describedby={describedBy}
              autoComplete="off"
            />
          )}
        </Field>
      </StepForm>
    );
  }

  if (step === 'registration') {
    return (
      <StepForm
        busy={busy}
        submitLabel="Save access"
        onSubmit={(data) => onSubmit('registration', { mode: data.get('mode') })}
      >
        <Field
          label="Registration"
          hint="Closed is the private default. Invite lets you hand out one-time links."
          error={first(errors, 'mode')}
        >
          {({ id, describedBy }) => (
            <Select
              id={id}
              name="mode"
              aria-describedby={describedBy}
              defaultValue={values?.registration_mode ?? 'closed'}
            >
              <option value="closed">Closed</option>
              <option value="invite">Invitation only</option>
              <option value="open">Open</option>
            </Select>
          )}
        </Field>
      </StepForm>
    );
  }

  const mailComplete = state?.steps.find((entry) => entry.step === 'mail')?.complete ?? false;

  if (mailComplete) {
    return (
      <div className="flex flex-col gap-5">
        <p className="text-ink-muted text-sm">
          Every step is done. Finishing marks this instance installed, closes setup permanently and
          spends the setup token.
        </p>
        <div>
          <Button
            intent="primary"
            size="lg"
            onClick={() => void onFinish()}
            disabled={busy}
            data-testid="finish-setup"
          >
            {busy ? 'Finishing' : 'Finish setup'}
          </Button>
        </div>
      </div>
    );
  }

  return (
    <StepForm
      busy={busy}
      submitLabel="Save mail"
      secondary={{
        label: 'Skip for now',
        onClick: () => void onSubmit('mail', { skip: true }),
        testId: 'skip-mail',
      }}
      onSubmit={(data) =>
        onSubmit('mail', {
          host: data.get('host') || null,
          port: Number(data.get('port')) || null,
          username: data.get('username') || null,
          password: data.get('password') || null,
          from_address: data.get('from_address') || null,
        })
      }
    >
      <Field label="SMTP host" error={first(errors, 'host')}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            name="host"
            aria-describedby={describedBy}
            defaultValue={values?.mail_host ?? ''}
          />
        )}
      </Field>
      <Field label="Port" error={first(errors, 'port')}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            name="port"
            type="number"
            aria-describedby={describedBy}
            defaultValue={values?.mail_port ?? 587}
          />
        )}
      </Field>
      <Field label="Username" error={first(errors, 'username')}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            name="username"
            aria-describedby={describedBy}
            autoComplete="off"
            defaultValue={values?.mail_username ?? ''}
          />
        )}
      </Field>
      <Field label="Password" error={first(errors, 'password')}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            name="password"
            type="password"
            aria-describedby={describedBy}
            autoComplete="off"
          />
        )}
      </Field>
      <Field label="From address" error={first(errors, 'from_address')}>
        {({ id, describedBy }) => (
          <Input
            id={id}
            name="from_address"
            type="email"
            aria-describedby={describedBy}
            defaultValue={values?.mail_from_address ?? ''}
          />
        )}
      </Field>
    </StepForm>
  );
}

function StepForm({
  busy,
  submitLabel,
  secondary,
  onSubmit,
  children,
}: {
  busy: boolean;
  submitLabel: string;
  secondary?: { label: string; onClick: () => void; testId: string };
  onSubmit: (data: FormData) => void;
  children: React.ReactNode;
}) {
  return (
    <form
      className="flex flex-col gap-5"
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit(new FormData(event.currentTarget));
      }}
    >
      {children}

      <div className="flex flex-wrap items-center gap-3">
        <Button intent="primary" size="lg" type="submit" disabled={busy} data-testid="step-submit">
          {busy ? 'Saving' : submitLabel}
        </Button>

        {secondary ? (
          <Button
            intent="ghost"
            size="lg"
            type="button"
            onClick={secondary.onClick}
            disabled={busy}
            data-testid={secondary.testId}
          >
            {secondary.label}
          </Button>
        ) : null}
      </div>
    </form>
  );
}

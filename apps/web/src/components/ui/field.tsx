'use client';

import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';
import { useId } from 'react';

import { cn } from '@/lib/cn';

/**
 * The form primitives the setup wizard needs.
 *
 * A hairline border and a focus ring drawn in the accent, matching the button
 * set. The error is rendered in the flow rather than as a floating hint, so a
 * long validation message from the API cannot cover the next field.
 */
export function Field({
  label,
  hint,
  error,
  children,
}: {
  label: string;
  hint?: ReactNode;
  error?: string;
  children: (props: { id: string; describedBy: string | undefined }) => ReactNode;
}) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ');

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-ink text-xs font-medium tracking-tight">
        {label}
      </label>

      {children({ id, describedBy: describedBy === '' ? undefined : describedBy })}

      {hint ? (
        <p id={hintId} className="text-ink-subtle text-xs">
          {hint}
        </p>
      ) : null}

      {error ? (
        <p id={errorId} className="text-critical text-xs" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}

const control = [
  'border-border bg-surface text-ink w-full rounded-(--radius-token-sm) border px-3 py-2 text-sm',
  'transition-[border-color,box-shadow] duration-[--duration-press] ease-(--ease-out)',
  'placeholder:text-ink-subtle',
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--accent)',
  'disabled:opacity-50',
];

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={cn(control, className)} {...props} />;
}

export function Select({ className, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select className={cn(control, className)} {...props} />;
}

export function Checkbox({
  label,
  description,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string; description?: string }) {
  const id = useId();

  return (
    <div className="flex items-start gap-3">
      <input
        id={id}
        type="checkbox"
        className="border-border mt-0.5 size-4 rounded-[3px] border accent-(--accent)"
        {...props}
      />
      <div className="min-w-0">
        <label htmlFor={id} className="text-ink text-xs font-medium">
          {label}
        </label>
        {description ? <p className="text-ink-subtle mt-0.5 text-xs">{description}</p> : null}
      </div>
    </div>
  );
}

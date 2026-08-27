'use client';

import { useState, type ReactNode } from 'react';

import { CaretRight } from '@/components/icons';

/**
 * A section an operator opens when they want it.
 *
 * No animation. This sits inside a form an operator uses many times a day, and
 * the motion contract is explicit that a surface seen that often gets none — the
 * content is simply there or not, which is also the only behaviour that cannot
 * be mid-transition when someone tabs into it.
 */
export function Disclosure({
  label,
  children,
  testId,
}: {
  label: string;
  children: ReactNode;
  testId?: string;
}) {
  const [open, setOpen] = useState(false);

  return (
    <div className="border-border border-t pt-4">
      <button
        type="button"
        className="text-ink flex w-full items-center gap-2 text-left text-sm font-medium"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
        data-testid={testId}
      >
        <CaretRight
          size={14}
          className={`text-ink-muted shrink-0 transition-transform duration-(--duration-press) ease-(--ease-out) ${open ? 'rotate-90' : ''}`}
        />
        {label}
      </button>

      {open ? <div className="mt-4">{children}</div> : null}
    </div>
  );
}

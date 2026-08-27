'use client';

import { Tooltip as BaseTooltip } from '@base-ui/react/tooltip';
import type { ReactNode } from 'react';

import { cn } from '@/lib/cn';

/**
 * Tooltips scale from their trigger, not from their centre.
 *
 * `var(--transform-origin)` is supplied by base-ui and is the reason using it
 * beats hand-rolling a popover: origin-awareness becomes a one-line concern
 * instead of a calculation per component.
 *
 * A delay before the first tooltip prevents accidental activation; once one is
 * open, adjacent ones open instantly, which makes a toolbar feel faster without
 * losing that protection.
 */
export function TooltipProvider({ children }: { children: ReactNode }) {
  return (
    <BaseTooltip.Provider delay={400} closeDelay={0}>
      {children}
    </BaseTooltip.Provider>
  );
}

export function Tooltip({ label, children }: { label: ReactNode; children: ReactNode }) {
  return (
    <BaseTooltip.Root>
      <BaseTooltip.Trigger render={<span className="inline-flex" />}>
        {children}
      </BaseTooltip.Trigger>
      <BaseTooltip.Portal>
        <BaseTooltip.Positioner sideOffset={6}>
          <BaseTooltip.Popup
            className={cn(
              'border-border origin-(--transform-origin) rounded-(--radius-token-sm) border',
              'bg-surface-raised text-ink px-2 py-1 text-xs shadow-none',
              'transition-[transform,opacity] duration-(--duration-tooltip) ease-(--ease-out)',
              // Never from nothing: a tooltip that grows from zero looks like it
              // came out of the void.
              'data-[starting-style]:scale-95 data-[starting-style]:opacity-0',
              'data-[ending-style]:scale-95 data-[ending-style]:opacity-0',
              // A second tooltip in the same toolbar appears with no animation.
              'data-[instant]:duration-0',
            )}
          >
            {label}
          </BaseTooltip.Popup>
        </BaseTooltip.Positioner>
      </BaseTooltip.Portal>
    </BaseTooltip.Root>
  );
}

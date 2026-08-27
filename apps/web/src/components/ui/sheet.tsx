'use client';

import { Dialog } from '@base-ui/react/dialog';
import type { ReactNode } from 'react';

import { cn } from '@/lib/cn';

/**
 * The editing surface.
 *
 * Occasional rather than constant, so it earns a real transition — 260ms on the
 * drawer curve. It enters and leaves along the same edge, which is what makes
 * dismissing it feel obvious.
 *
 * base-ui handles focus trapping, focus return and dismissal. Those are the parts
 * a hand-rolled panel gets wrong.
 */
export function Sheet({
  open,
  onOpenChange,
  title,
  description,
  children,
  footer,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Backdrop
          className={cn(
            'bg-ink/25 fixed inset-0 z-40',
            'transition-opacity duration-(--duration-sheet) ease-(--ease-out)',
            'data-[ending-style]:duration-(--duration-sheet-exit)',
            'data-[ending-style]:opacity-0 data-[starting-style]:opacity-0',
          )}
        />
        <Dialog.Popup
          className={cn(
            'fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col',
            // The one surface that genuinely floats above everything, so it
            // takes the overlay level rather than the raised one.
            'bg-surface shadow-(--shadow-overlay)',
            'motion-travels transition-transform duration-(--duration-sheet) ease-(--ease-drawer)',
            // Exit is faster than entry, and along the same edge it came from.
            'data-[ending-style]:duration-(--duration-sheet-exit)',
            // Percentage translate, so the distance is the panel's own width
            // whatever that turns out to be.
            'data-[ending-style]:translate-x-full data-[starting-style]:translate-x-full',
          )}
        >
          <div className="border-border/70 border-b px-6 py-4">
            <Dialog.Title className="text-ink text-sm font-semibold tracking-tight">
              {title}
            </Dialog.Title>
            {description ? (
              <Dialog.Description className="text-ink-muted mt-1 text-xs">
                {description}
              </Dialog.Description>
            ) : null}
          </div>

          <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>

          {footer ? <div className="border-border border-t px-6 py-4">{footer}</div> : null}
        </Dialog.Popup>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

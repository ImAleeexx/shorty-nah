'use client';

import { cva, type VariantProps } from 'class-variance-authority';
import type { ButtonHTMLAttributes, ReactNode } from 'react';

import { cn } from '@/lib/cn';

/**
 * Variants are typed rather than assembled from conditional strings, so a
 * component never needs a later class to defeat an earlier one.
 *
 * Every variant presses, and the press is asymmetric on purpose. Going down runs
 * on `--ease-out`; coming back runs on `--ease-spring`, which overshoots
 * slightly and is what reads as a physical release. The curves are the other way
 * round from what looks natural in a stylesheet — an overshoot on the way *down*
 * pushes past the target and the control looks broken.
 */
const button = cva(
  [
    'inline-flex items-center justify-center gap-2 rounded-(--radius-token-sm)',
    'text-sm font-medium whitespace-nowrap select-none',
    'transition-[transform,background-color,border-color,color,box-shadow]',
    'duration-(--duration-press) ease-(--ease-spring)',
    'active:duration-(--duration-press) active:ease-(--ease-out) active:scale-[0.98]',
    'disabled:pointer-events-none disabled:opacity-50',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--accent)',
  ],
  {
    variants: {
      intent: {
        // Solid ink, not a coloured slab: colour is still a scarce resource, and
        // the elevation now carries the emphasis the colour used to.
        primary: 'bg-ink text-canvas shadow-(--shadow-raised) hover:bg-ink/90',
        accent: 'bg-accent text-accent-contrast shadow-(--shadow-raised) hover:bg-accent-hover',
        // A raised surface rather than an outline. The border survives at low
        // opacity to hold the edge where a shadow alone would let the fill
        // dissolve into the canvas.
        outline:
          'border-border/60 bg-surface text-ink border shadow-(--shadow-card) hover:border-border-strong',
        // The one variant with no elevation, because a tertiary control that
        // floats is not tertiary.
        ghost: 'text-ink-muted hover:bg-accent-muted hover:text-ink',
        critical:
          'border-border/60 bg-surface text-critical border shadow-(--shadow-card) hover:border-critical',
      },
      size: {
        sm: 'h-8 px-3 text-xs',
        md: 'h-9 px-4',
        lg: 'h-11 px-5',
        icon: 'size-9',
      },
    },
    defaultVariants: { intent: 'outline', size: 'md' },
  },
);

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> &
  VariantProps<typeof button> & { children?: ReactNode };

export function Button({ className, intent, size, ...props }: ButtonProps) {
  return <button className={cn(button({ intent, size }), className)} {...props} />;
}

export { button as buttonVariants };

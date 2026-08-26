'use client';

import { cva, type VariantProps } from 'class-variance-authority';
import type { ButtonHTMLAttributes, ReactNode } from 'react';

import { cn } from '@/lib/cn';

/**
 * Variants are typed rather than assembled from conditional strings, so a
 * component never needs a later class to defeat an earlier one.
 *
 * Every variant presses. `active:scale-[0.98]` is the smallest change that makes
 * an interface feel like it heard the viewer, and it is the one animation that
 * belongs on a surface used constantly.
 */
const button = cva(
  [
    'inline-flex items-center justify-center gap-2 rounded-(--radius-token-sm)',
    'text-sm font-medium whitespace-nowrap select-none',
    'transition-[transform,background-color,border-color,color] duration-[--duration-press]',
    'ease-(--ease-out) active:scale-[0.98]',
    'disabled:pointer-events-none disabled:opacity-50',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--accent)',
  ],
  {
    variants: {
      intent: {
        // Solid ink, not a coloured slab: colour is a scarce resource here.
        primary: 'bg-ink text-canvas hover:bg-ink/90',
        accent: 'bg-accent text-accent-contrast hover:bg-accent-hover',
        // A hairline border, never a shadow.
        outline: 'border border-border bg-surface text-ink hover:border-border-strong',
        ghost: 'text-ink-muted hover:bg-accent-muted hover:text-ink',
        critical: 'border border-border bg-surface text-critical hover:border-critical',
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

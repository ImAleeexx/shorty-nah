import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/cn';

/**
 * The asymmetric grid.
 *
 * Twelve columns above the fold width so cells can be genuinely different sizes,
 * and a single column below it. Every span override resets at the breakpoint:
 * an asymmetric layout on a phone produces overlapping touch targets, not a
 * clever composition.
 */
export function BentoGrid({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={cn('grid grid-cols-1 gap-4 md:grid-cols-12 md:gap-5', className)} {...props} />
  );
}

const SPANS = {
  full: 'md:col-span-12',
  half: 'md:col-span-6',
  third: 'md:col-span-4',
  twoThirds: 'md:col-span-8',
  quarter: 'md:col-span-3',
} as const;

export function BentoCell({
  span = 'third',
  className,
  ...props
}: HTMLAttributes<HTMLDivElement> & { span?: keyof typeof SPANS }) {
  // col-span-1 first, so the single-column fallback is the default rather than
  // something the breakpoint has to undo.
  return <div className={cn('col-span-1', SPANS[span], className)} {...props} />;
}

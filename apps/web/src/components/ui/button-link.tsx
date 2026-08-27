'use client';

import Link from 'next/link';
import type { ReactNode } from 'react';

import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/cn';

/**
 * A link that looks like a button.
 *
 * A component rather than calling buttonVariants() at the call site: the
 * variants live in a client module, and invoking them from a server component
 * throws at render — which type-checking and the build both let through,
 * because it is a boundary error rather than a type error.
 */
export function ButtonLink({
  href,
  children,
  intent = 'outline',
  size = 'md',
  testId,
}: {
  href: string;
  children: ReactNode;
  intent?: 'primary' | 'accent' | 'outline' | 'ghost' | 'critical';
  size?: 'sm' | 'md' | 'lg';
  testId?: string;
}) {
  return (
    <Link href={href} className={cn(buttonVariants({ intent, size }))} data-testid={testId}>
      {children}
    </Link>
  );
}

'use client';

import Link from 'next/link';

import { Plus } from '@/components/icons';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/cn';

/**
 * Opens the link sheet from anywhere that is not the link list.
 *
 * A link, not a button: the sheet lives with the list that has to refresh when
 * a link is created, and duplicating it here would mean two implementations of
 * the same form drifting apart. The list opens it on arrival.
 */
export function NewLinkButton({
  label = 'New link',
  intent = 'primary',
  size = 'md',
  iconSize = 15,
}: {
  label?: string;
  intent?: 'primary' | 'accent' | 'outline' | 'ghost';
  size?: 'sm' | 'md' | 'lg';
  iconSize?: number;
}) {
  return (
    <Link
      href="/links?new=1"
      className={cn(buttonVariants({ intent, size }))}
      data-testid="overview-new-link"
    >
      <Plus size={iconSize} />
      {label}
    </Link>
  );
}

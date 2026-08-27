'use client';

import { Plus } from '@/components/icons';
import { ButtonLink } from '@/components/ui/button-link';

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
    <ButtonLink href="/links?new=1" intent={intent} size={size} testId="overview-new-link">
      <Plus size={iconSize} />
      {label}
    </ButtonLink>
  );
}

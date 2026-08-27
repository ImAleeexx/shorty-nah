'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';

import { cn } from '@/lib/cn';

const DESTINATIONS = [
  { href: '/', label: 'Overview', ownerOnly: false },
  { href: '/links', label: 'Links', ownerOnly: false },
  { href: '/people', label: 'People', ownerOnly: false },
  { href: '/security', label: 'Security', ownerOnly: false },
  { href: '/settings', label: 'Settings', ownerOnly: false },
  // The audit log is an owner surface, so it is absent rather than disabled for
  // anyone else — a greyed-out link still discloses that it exists.
  { href: '/audit', label: 'Audit', ownerOnly: true },
] as const;

/**
 * Primary navigation.
 *
 * No transition on the active state: this is a surface an operator moves through
 * dozens of times a day, and animating it turns navigation into a wait.
 */
export function AppNav({ owner = false }: { owner?: boolean }) {
  const pathname = usePathname();

  return (
    <nav className="mr-2 flex items-center gap-1">
      {DESTINATIONS.filter((destination) => owner || !destination.ownerOnly).map((destination) => {
        const active =
          destination.href === '/' ? pathname === '/' : pathname.startsWith(destination.href);

        return (
          <Link
            key={destination.href}
            href={destination.href}
            aria-current={active ? 'page' : undefined}
            className={cn(
              'rounded-(--radius-token-sm) px-3 py-1.5 text-sm',
              active ? 'text-ink font-medium' : 'text-ink-muted hover:text-ink',
            )}
          >
            {destination.label}
          </Link>
        );
      })}
    </nav>
  );
}

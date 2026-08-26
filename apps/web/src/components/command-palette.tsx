'use client';

import { Command } from 'cmdk';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';

import type { LinkRecord } from '@/lib/links';

/**
 * Search, creation and navigation from the keyboard.
 *
 * Opened a hundred times a day, so it has no open or close animation at all:
 * the motion contract puts this surface past the frequency threshold where any
 * transition reads as the interface being slow. Focus return on dismissal is the
 * part that must not be skipped — it is what makes the palette feel like a layer
 * over the page rather than a navigation away from it.
 */
export function CommandPalette({ links }: { links: LinkRecord[] }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [origin, setOrigin] = useState<HTMLElement | null>(null);

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'k' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        setOrigin(document.activeElement instanceof HTMLElement ? document.activeElement : null);
        setOpen((previous) => !previous);
      }
    }

    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, []);

  const dismiss = useCallback(() => {
    setOpen(false);

    // Returning focus where it was is what a dismissed overlay owes the viewer;
    // without it the next Tab starts from the top of the document. Deferred by a
    // frame because the dialog runs its own focus restoration on close, and a
    // synchronous call here is simply overwritten by it.
    requestAnimationFrame(() => origin?.focus());
  }, [origin]);

  function go(href: string) {
    dismiss();
    router.push(href);
  }

  return (
    <Command.Dialog
      open={open}
      onOpenChange={(next) => (next ? setOpen(true) : dismiss())}
      label="Command palette"
      className="fixed inset-0 z-50"
      data-testid="command-palette"
    >
      <div className="bg-ink/20 absolute inset-0" onClick={dismiss} aria-hidden="true" />

      <div className="border-border bg-surface relative mx-auto mt-[12vh] w-full max-w-lg overflow-hidden rounded-(--radius-token) border">
        <Command.Input
          placeholder="Search links, or jump to a page"
          className="border-border text-ink placeholder:text-ink-subtle w-full border-b bg-transparent px-4 py-3 text-sm outline-none"
          data-testid="palette-input"
        />

        <Command.List className="max-h-80 overflow-y-auto p-2">
          <Command.Empty className="text-ink-muted px-2 py-6 text-center text-sm">
            Nothing matches.
          </Command.Empty>

          <Command.Group
            heading="Go to"
            className="text-ink-subtle [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs"
          >
            <PaletteItem onSelect={() => go('/')}>Overview</PaletteItem>
            <PaletteItem onSelect={() => go('/links')}>Links</PaletteItem>
          </Command.Group>

          {links.length > 0 ? (
            <Command.Group
              heading="Links"
              className="text-ink-subtle [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs"
            >
              {links.map((link) => (
                <PaletteItem
                  key={link.id}
                  value={`${link.slug} ${link.destination} ${link.tags.join(' ')}`}
                  onSelect={() => go('/links')}
                >
                  <span className="tabular">/{link.slug}</span>
                  <span className="text-ink-subtle ml-2 truncate text-xs">{link.destination}</span>
                </PaletteItem>
              ))}
            </Command.Group>
          ) : null}
        </Command.List>
      </div>
    </Command.Dialog>
  );
}

function PaletteItem({
  children,
  value,
  onSelect,
}: {
  children: React.ReactNode;
  value?: string;
  onSelect: () => void;
}) {
  return (
    <Command.Item
      value={value}
      onSelect={onSelect}
      className="text-ink data-[selected=true]:bg-accent-muted flex cursor-default items-center rounded-(--radius-token-sm) px-2 py-2 text-sm"
    >
      {children}
    </Command.Item>
  );
}

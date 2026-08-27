'use client';

import { useTheme } from 'next-themes';

import { Monitor, Moon, Sun } from '@/components/icons';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/cn';
import { useMounted } from '@/lib/use-mounted';

const OPTIONS = [
  { value: 'light', label: 'Light', Icon: Sun },
  { value: 'dark', label: 'Dark', Icon: Moon },
  { value: 'system', label: 'System', Icon: Monitor },
] as const;

/**
 * Three states, not two: following the system is a choice, and a switch loses it.
 *
 * The active state is withheld until after hydration, and that is the whole
 * point. This previously read `theme` directly, on the reasoning that
 * next-themes returns undefined before hydration — but it returns undefined on
 * the *server* and the stored theme during the client's hydration render, so
 * every attribute derived from it differed between the two. React reported a
 * tree that "hydrated but some attributes of the server rendered HTML didn't
 * match", and said it would not patch them up: on a dark instance the pressed
 * button kept the server's unpressed markup.
 *
 * Reading `mounted` first makes the hydration render identical to the server's,
 * and the real state arrives in the update immediately after.
 */
export function ThemeToggle() {
  const { theme, setTheme } = useTheme();
  const mounted = useMounted();

  return (
    <div
      className="border-border flex items-center gap-0.5 rounded-(--radius-token-sm) border p-0.5"
      role="group"
      aria-label="Colour mode"
    >
      {OPTIONS.map(({ value, label, Icon }) => {
        const active = mounted && theme === value;

        return (
          <Tooltip key={value} label={label}>
            <button
              type="button"
              onClick={() => setTheme(value)}
              aria-label={label}
              aria-pressed={active}
              className={cn(
                'flex size-7 items-center justify-center rounded-[calc(var(--radius)-6px)]',
                'transition-[background-color,color] duration-(--duration-press) ease-(--ease-out)',
                'active:scale-[0.98]',
                active ? 'bg-accent-muted text-ink' : 'text-ink-subtle hover:text-ink',
              )}
            >
              <Icon size={15} weight={active ? 'bold' : 'regular'} />
            </button>
          </Tooltip>
        );
      })}
    </div>
  );
}

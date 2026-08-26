'use client';

import { useTheme } from 'next-themes';

import { Monitor, Moon, Sun } from '@/components/icons';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/cn';

const OPTIONS = [
  { value: 'light', label: 'Light', Icon: Sun },
  { value: 'dark', label: 'Dark', Icon: Moon },
  { value: 'system', label: 'System', Icon: Monitor },
] as const;

/**
 * Three states, not two: following the system is a choice, and a switch loses it.
 *
 * No mount effect. next-themes returns undefined before hydration, so the active
 * state is false on the first render and correct after it — which is the same
 * outcome without a state update inside an effect.
 */
export function ThemeToggle() {
  const { theme, setTheme } = useTheme();

  return (
    <div
      className="border-border flex items-center gap-0.5 rounded-(--radius-token-sm) border p-0.5"
      role="group"
      aria-label="Colour mode"
    >
      {OPTIONS.map(({ value, label, Icon }) => {
        const active = theme === value;

        return (
          <Tooltip key={value} label={label}>
            <button
              type="button"
              onClick={() => setTheme(value)}
              aria-label={label}
              aria-pressed={active}
              className={cn(
                'flex size-7 items-center justify-center rounded-[calc(var(--radius)-6px)]',
                'transition-[background-color,color] duration-[--duration-press] ease-(--ease-out)',
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

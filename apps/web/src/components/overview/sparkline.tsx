/**
 * Thirty days of counted clicks.
 *
 * Plain markup rather than a chart library: thirty bars need no scales, no axes
 * and no client runtime, and rendering it on the server keeps the figure present
 * on first paint like every other number on this page.
 *
 * One series, so there is no legend — the heading names it. The exact totals are
 * stated as text beside the shape, which is what makes the bars a supporting
 * visual rather than the only way to read the number.
 */
export function Sparkline({ days }: { days: { day: string; counted: number }[] }) {
  const peak = Math.max(...days.map((entry) => entry.counted), 1);

  return (
    <div
      className="flex h-16 items-end gap-px"
      role="img"
      aria-label={`Counted clicks per day over the last ${days.length} days`}
      data-testid="overview-sparkline"
    >
      {days.map((entry) => {
        // A day with one click never renders as nothing: a zero-height bar and a
        // one-click bar would otherwise look identical, which is the shape
        // lying about a quiet day rather than an empty one.
        const height = Math.max(4, (entry.counted / peak) * 100);

        return (
          <div
            key={entry.day}
            className="flex-1"
            style={{ height: entry.counted === 0 ? '2px' : `${height}%` }}
          >
            <div
              className={
                entry.counted === 0
                  ? 'bg-border h-full w-full rounded-[1px]'
                  : 'bg-accent h-full w-full rounded-t-[3px]'
              }
              title={`${entry.day}: ${entry.counted.toLocaleString()}`}
            />
          </div>
        );
      })}
    </div>
  );
}

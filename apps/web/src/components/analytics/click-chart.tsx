'use client';

import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { formatBucket, type Report, type SeriesPoint } from '@/lib/analytics';

/**
 * One measure over time.
 *
 * Deliberately single-series. Clicks and unique visitors are different measures,
 * and the two honest ways to show them together are a second axis — never — or
 * small multiples. This is the multiple: two of these side by side, each naming
 * its own measure, so no legend is needed and the accent means the same thing in
 * both.
 */
export function ClickChart({
  title,
  series,
  granularity,
  measure,
}: {
  title: string;
  series: SeriesPoint[];
  granularity: Report['period']['granularity'];
  measure: 'counted' | 'visitors';
}) {
  const data = series.map((point) => ({
    label: formatBucket(point.bucket, granularity),
    value: point[measure],
  }));

  return (
    <figure className="flex flex-col gap-3">
      <figcaption className="text-ink-subtle text-xs font-medium tracking-wide uppercase">
        {title}
      </figcaption>

      <div className="h-48 w-full" data-testid={`chart-${measure}`}>
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
            <defs>
              {/* A single flat wash, not a gradient for decoration: it separates
                  the filled region from the grid without adding a second value. */}
              <linearGradient id={`wash-${measure}`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="var(--accent)" stopOpacity={0.14} />
                <stop offset="100%" stopColor="var(--accent)" stopOpacity={0.02} />
              </linearGradient>
            </defs>

            {/* Recessive: the grid is a reading aid, never a mark. */}
            <CartesianGrid stroke="var(--border)" strokeDasharray="2 4" vertical={false} />

            <XAxis
              dataKey="label"
              tickLine={false}
              axisLine={false}
              minTickGap={24}
              tick={{ fill: 'var(--ink-subtle)', fontSize: 11 }}
            />
            <YAxis
              allowDecimals={false}
              width={36}
              tickLine={false}
              axisLine={false}
              tick={{ fill: 'var(--ink-subtle)', fontSize: 11 }}
            />

            <Tooltip
              cursor={{ stroke: 'var(--border-strong)', strokeWidth: 1 }}
              content={({ active, payload, label }) =>
                active && payload && payload.length > 0 ? (
                  <div className="border-border bg-surface rounded-(--radius-token-sm) border px-3 py-2">
                    <p className="text-ink-subtle text-xs">{String(label)}</p>
                    <p className="tabular text-ink text-sm font-medium">
                      {Number(payload[0]?.value ?? 0).toLocaleString()} {title.toLowerCase()}
                    </p>
                  </div>
                ) : null
              }
            />

            <Area
              type="monotone"
              dataKey="value"
              stroke="var(--accent)"
              strokeWidth={2}
              fill={`url(#wash-${measure})`}
              // The dot appears on hover only; a marker on every point turns a
              // trend into a scatter.
              dot={false}
              activeDot={{ r: 4, strokeWidth: 2, stroke: 'var(--surface)' }}
            />
          </AreaChart>
        </ResponsiveContainer>
      </div>
    </figure>
  );
}

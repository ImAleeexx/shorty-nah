export type ReportTotals = {
  clicks: number;
  counted: number;
  automated: number;
  duplicates: number;
  visitors: number;
};

export type SeriesPoint = {
  bucket: string;
  clicks: number;
  counted: number;
  visitors: number;
};

export type Breakdown = { label: string; counted: number };

export type Report = {
  link: { id: string; slug: string };
  period: {
    from: string;
    to: string;
    granularity: 'hour' | 'day' | 'month';
    timezone: string;
  };
  totals: ReportTotals;
  series: SeriesPoint[];
  countries: { country: string; counted: number; visitors: number }[];
  referrers: { referrer: string; counted: number }[];
  clients: {
    devices: Breakdown[];
    operating_systems: Breakdown[];
    browsers: Breakdown[];
  };
};

export type ClickEvent = Record<string, unknown> & { occurred_at?: string };

export type EventPage = {
  events: ClickEvent[];
  meta: { page: number; per_page: number; total: number };
};

/**
 * Bucket labels come back as instants. The granularity decides how much of one a
 * reader needs: an hour bucket labelled with its date repeats the same date
 * twenty-four times.
 */
export function formatBucket(bucket: string, granularity: Report['period']['granularity']): string {
  const at = new Date(bucket);

  if (Number.isNaN(at.getTime())) {
    return bucket;
  }

  if (granularity === 'hour') {
    return at.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  }

  if (granularity === 'month') {
    return at.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
  }

  return at.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

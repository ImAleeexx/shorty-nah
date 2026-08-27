const REGION_NAMES =
  typeof Intl !== 'undefined' && 'DisplayNames' in Intl
    ? new Intl.DisplayNames(['en'], { type: 'region' })
    : null;

function countryName(code: string): string {
  try {
    return REGION_NAMES?.of(code) ?? code;
  } catch {
    return code;
  }
}

/**
 * Where the clicks came from, ranked.
 *
 * Direct-labelled rather than given a legend: five rows carrying their own name
 * and their own figure need no key, and colour is doing no work here beyond
 * showing proportion — one hue, because this is magnitude, not identity.
 */
export function CountryBars({
  countries,
}: {
  countries: { country_code: string; counted: number }[];
}) {
  const peak = Math.max(...countries.map((entry) => entry.counted), 1);

  return (
    <ul className="flex flex-col gap-3" data-testid="overview-countries">
      {countries.map((entry) => (
        <li key={entry.country_code} className="flex flex-col gap-1.5">
          <div className="flex items-baseline justify-between gap-3">
            <span className="text-ink truncate text-sm">{countryName(entry.country_code)}</span>
            <span className="tabular text-ink-muted text-xs">{entry.counted.toLocaleString()}</span>
          </div>

          <div className="bg-accent-muted h-1.5 w-full overflow-hidden rounded-[2px]">
            <div
              className="bg-accent h-full rounded-[2px]"
              style={{ width: `${Math.max(2, (entry.counted / peak) * 100)}%` }}
            />
          </div>
        </li>
      ))}
    </ul>
  );
}

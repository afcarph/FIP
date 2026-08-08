'use client';

import { Search as SearchIcon, X } from 'lucide-react';
import * as React from 'react';

import { Card, PriceRange, QueryState } from '@/components/doe/states';
import {
  FUEL_TYPE_OPTIONS,
  useDoeAreas,
  useDoeBrands,
  useDoeRegions,
  useDoeSearch,
  type DoeFilters,
} from '@/hooks/use-doe';

/**
 * `fuel_types.code` as the API returns it, with the DOE's own product label.
 *
 * Hard-coded rather than derived from the data: the list is fixed by the
 * platform's fuel types, and deriving it from whatever the current page
 * happens to contain would make the filter options change as you filter.
 */

/** Debounce so a typed area name does not fire a request per keystroke. */
function useDebounced<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = React.useState(value);

  React.useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);

    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1">
      <span className="text-xs font-medium text-slate-600 dark:text-slate-400">{label}</span>
      {children}
    </label>
  );
}

const CONTROL =
  'h-9 rounded-md border border-slate-300 bg-white px-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';

export default function DoeSearchPage() {
  const [region, setRegion] = React.useState('');
  const [area, setArea] = React.useState('');
  const [brand, setBrand] = React.useState('');
  const [fuelCode, setFuelCode] = React.useState('');
  const [week, setWeek] = React.useState('');

  const debouncedArea = useDebounced(area);

  const filters: DoeFilters = React.useMemo(() => {
    const next: DoeFilters = { per_page: 100 };

    if (region) next.region = region;
    if (debouncedArea) next.area = debouncedArea;
    if (brand) next.brand = brand;
    if (fuelCode) next.fuel_code = fuelCode;

    if (week) {
      // A coverage week is a span, not a day: a report matches when its window
      // contains the chosen date, so both bounds are sent.
      next.date_from = week;
      next.date_to = week;
    }

    return next;
  }, [region, debouncedArea, brand, fuelCode, week]);

  const { data, isLoading, isFetching, error, refetch } = useDoeSearch(filters);
  const { data: regions } = useDoeRegions();
  const { data: areas } = useDoeAreas(region || undefined);
  const { data: brands } = useDoeBrands(region || undefined);

  const rows = data?.prices ?? [];
  const total = data?.page?.total;
  const hasFilters = Boolean(region || area || brand || fuelCode || week);

  const clear = () => {
    setRegion('');
    setArea('');
    setBrand('');
    setFuelCode('');
    setWeek('');
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-slate-50">Search</h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Published prices by region, area, brand, fuel type and coverage week.
        </p>
      </div>

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <Field label="Region">
            <select className={CONTROL} value={region} onChange={(e) => setRegion(e.target.value)}>
              <option value="">All regions</option>
              {(regions ?? []).map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Area">
            <input
              className={CONTROL}
              list="doe-areas"
              value={area}
              placeholder="e.g. Quezon City"
              onChange={(e) => setArea(e.target.value)}
            />
            {/* A datalist rather than a select: 88 areas is too many to scroll,
                and the API matches partial names anyway. */}
            <datalist id="doe-areas">
              {(areas ?? []).map((entry) => (
                <option key={`${entry.region}-${entry.area}`} value={entry.area} />
              ))}
            </datalist>
          </Field>

          <Field label="Brand">
            <select className={CONTROL} value={brand} onChange={(e) => setBrand(e.target.value)}>
              <option value="">All brands</option>
              {(brands ?? []).map((entry) => (
                <option key={entry.brand} value={entry.brand}>
                  {entry.brand}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Fuel type">
            <select
              className={CONTROL}
              value={fuelCode}
              onChange={(e) => setFuelCode(e.target.value)}
            >
              <option value="">All fuel types</option>
              {FUEL_TYPE_OPTIONS.map((fuel) => (
                <option key={fuel.code} value={fuel.code}>
                  {fuel.label}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Coverage week">
            <input
              type="date"
              className={CONTROL}
              value={week}
              onChange={(e) => setWeek(e.target.value)}
            />
          </Field>
        </div>

        <div className="mt-3 flex items-center justify-between gap-3">
          <p className="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <SearchIcon className="size-3.5" aria-hidden />
            {isFetching ? 'Searching…' : `${total ?? rows.length} matching record${(total ?? rows.length) === 1 ? '' : 's'}`}
          </p>

          {hasFilters ? (
            <button
              type="button"
              onClick={clear}
              className="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            >
              <X className="size-3.5" aria-hidden />
              Clear filters
            </button>
          ) : null}
        </div>
      </Card>

      <Card className="p-0">
        <QueryState
          isLoading={isLoading}
          error={error}
          isEmpty={rows.length === 0}
          onRetry={() => void refetch()}
          emptyTitle="No prices match those filters"
          emptyHint="Try widening the region or clearing the coverage week — reports are weekly, so a mid-week date may fall outside every published window."
          loadingRows={8}
        >
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                <tr>
                  <th className="px-4 py-2 font-medium">Area</th>
                  <th className="px-4 py-2 font-medium">Province</th>
                  <th className="px-4 py-2 font-medium">Product</th>
                  <th className="px-4 py-2 font-medium">Brand</th>
                  <th className="px-4 py-2 font-medium">Published range</th>
                  <th className="px-4 py-2 font-medium">Common</th>
                  <th className="px-4 py-2 font-medium">Week</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <td className="whitespace-nowrap px-4 py-2 font-medium text-slate-900 dark:text-slate-100">
                      {row.area}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-slate-600 dark:text-slate-400">
                      {row.province ?? '—'}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-slate-700 dark:text-slate-300">
                      {row.product}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2">
                      {row.is_overall ? (
                        // The DOE prints this as its own column rather than
                        // deriving it, so it is labelled rather than blank.
                        <span className="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                          All brands
                        </span>
                      ) : (
                        <span className="text-slate-700 dark:text-slate-300">{row.brand}</span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-slate-900 dark:text-slate-100">
                      <PriceRange min={row.min_price} max={row.max_price} />
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-700 dark:text-slate-300">
                      {row.common_price !== null ? `₱${row.common_price.toFixed(2)}` : '—'}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-500 dark:text-slate-400">
                      {row.report?.coverage_label ?? '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </Card>
    </div>
  );
}

'use client';

import * as React from 'react';

import { DataFreshness, freshnessOf, ageLabel, FreshnessBadge } from '@/components/doe/freshness';
import { Card, QueryState } from '@/components/doe/states';
import { FUEL_TYPE_OPTIONS, useDoeBrands, useDoeImports, useDoeSearch } from '@/hooks/use-doe';

/**
 * The DOE price explorer.
 *
 * Every row here is a published figure for an area, product and brand — never
 * a station price. The freshness column uses the same rule as the dashboard
 * and both mobile clients, because a second definition of "how old is this"
 * is one too many.
 */
export default function DoeExplorerPage() {
  const [region, setRegion] = React.useState('');
  const [area, setArea] = React.useState('');
  const [brand, setBrand] = React.useState('');
  const [fuelCode, setFuelCode] = React.useState('');
  const [week, setWeek] = React.useState('');

  const [debouncedArea, setDebouncedArea] = React.useState('');

  React.useEffect(() => {
    const timer = setTimeout(() => setDebouncedArea(area), 350);

    return () => clearTimeout(timer);
  }, [area]);

  const imports = useDoeImports();
  const brands = useDoeBrands();

  const filters = React.useMemo(
    () => ({
      region: region || undefined,
      area: debouncedArea || undefined,
      brand: brand || undefined,
      fuel_code: fuelCode || undefined,
      date_from: week || undefined,
      date_to: week || undefined,
      per_page: 100,
    }),
    [region, debouncedArea, brand, fuelCode, week],
  );

  const { data, isLoading, error, refetch } = useDoeSearch(filters);
  const rows = data?.prices ?? [];

  const regions = imports.data?.latest_reports ?? [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-slate-50">
          DOE price explorer
        </h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Weekly area monitoring published by the Department of Energy. Figures are per area,
          product and brand — not individual station prices.
        </p>
      </div>

      {regions.length > 0 ? <DataFreshness reports={regions} /> : null}

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <label className="text-xs font-medium text-slate-600 dark:text-slate-300">
            Region
            <select
              value={region}
              onChange={(event) => setRegion(event.target.value)}
              className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
            >
              <option value="">All regions</option>
              {[...new Set(regions.map((report) => report.region))].map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </label>

          <label className="text-xs font-medium text-slate-600 dark:text-slate-300">
            Area
            <input
              value={area}
              onChange={(event) => setArea(event.target.value)}
              placeholder="e.g. Quezon City"
              className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
            />
          </label>

          <label className="text-xs font-medium text-slate-600 dark:text-slate-300">
            Brand
            <select
              value={brand}
              onChange={(event) => setBrand(event.target.value)}
              className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
            >
              <option value="">All brands</option>
              {(brands.data ?? []).map((summary) => (
                <option key={summary.brand} value={summary.brand}>
                  {summary.brand}
                </option>
              ))}
            </select>
          </label>

          <label className="text-xs font-medium text-slate-600 dark:text-slate-300">
            Fuel type
            <select
              value={fuelCode}
              onChange={(event) => setFuelCode(event.target.value)}
              className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
            >
              <option value="">All fuel types</option>
              {FUEL_TYPE_OPTIONS.map((option) => (
                <option key={option.code} value={option.code}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="text-xs font-medium text-slate-600 dark:text-slate-300">
            Monitoring period
            <input
              type="date"
              value={week}
              onChange={(event) => setWeek(event.target.value)}
              className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
            />
          </label>
        </div>
      </Card>

      <Card className="p-0">
        <QueryState
          isLoading={isLoading}
          error={error}
          isEmpty={rows.length === 0}
          onRetry={() => void refetch()}
          emptyTitle="No published prices match those filters"
          emptyHint="The DOE publishes weekly, and not every area appears in every report."
          loadingRows={8}
        >
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                <tr>
                  <th className="px-4 py-2 font-medium">Region</th>
                  <th className="px-4 py-2 font-medium">Area</th>
                  <th className="px-4 py-2 font-medium">Brand</th>
                  <th className="px-4 py-2 font-medium">Fuel type</th>
                  <th className="px-4 py-2 font-medium">Price</th>
                  <th className="px-4 py-2 font-medium">Coverage</th>
                  <th className="px-4 py-2 font-medium">Freshness</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {rows.map((row) => {
                  const freshness = row.report ? freshnessOf(row.report) : null;

                  return (
                    <tr key={row.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                      <td className="whitespace-nowrap px-4 py-2 text-slate-700 dark:text-slate-300">
                        {row.report?.region ?? '—'}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 font-medium text-slate-900 dark:text-slate-100">
                        {row.area}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 text-slate-700 dark:text-slate-300">
                        {row.brand ?? 'All brands'}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 text-slate-700 dark:text-slate-300">
                        {row.product}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-900 dark:text-slate-100">
                        {row.min_price === null && row.max_price === null
                          ? '—'
                          : row.min_price !== null &&
                              row.max_price !== null &&
                              row.min_price !== row.max_price
                            ? `₱${row.min_price.toFixed(2)} – ₱${row.max_price.toFixed(2)}`
                            : `₱${(row.min_price ?? row.max_price ?? 0).toFixed(2)}`}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-500 dark:text-slate-400">
                        {row.report?.coverage_label ?? '—'}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2">
                        {freshness ? (
                          <span className="flex items-center gap-2">
                            <FreshnessBadge status={freshness.status} />
                            <span className="text-xs text-slate-500 dark:text-slate-400">
                              {ageLabel(freshness)}
                            </span>
                          </span>
                        ) : (
                          <span className="text-xs text-slate-400">Unknown</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </QueryState>
      </Card>

      <p className="text-xs text-slate-500 dark:text-slate-400">
        Source: Philippine Department of Energy. Weekly area price monitoring — not live station
        prices.
      </p>
    </div>
  );
}

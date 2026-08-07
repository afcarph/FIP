'use client';

import * as React from 'react';

import { Card } from '@/components/doe/states';
import type { DoeReport } from '@/hooks/use-doe';

/**
 * How current the newest report held for each region is.
 *
 * The counts elsewhere on the dashboard say how much data there is, which is a
 * different question from whether it is the data you should be looking at. A
 * region can sit at 363 rows and a quality of 1.00 while being a fortnight
 * behind — the numbers all read as healthy and the prices are simply old.
 */

/** DOE weeks run Tuesday to Monday. */
const WEEK_DAYS = 7;

export type Freshness = {
  /** Whole days since the covered week ended; 0 while it is still running. */
  ageDays: number;
  status: 'current' | 'stale' | 'behind';
  /** True when today falls inside the covered week. */
  isRunningWeek: boolean;
};

/**
 * Age is measured from the end of the week the report covers, not from when it
 * was published or imported. A report imported this morning that covers three
 * weeks ago is three weeks old, and measuring from the import would call it
 * fresh.
 */
export function freshnessOf(report: DoeReport, now: Date = new Date()): Freshness | null {
  // Compare whole days in local time. The API sends plain dates, and parsing
  // them as UTC instants would shift the boundary by the Manila offset and
  // report an extra day either side of midnight.
  const parsed = parseDate(report.coverage_end);

  // Null rather than a guess. Every alternative — treating it as today, as the
  // epoch, as behind — states an age the data does not support.
  if (Number.isNaN(parsed.getTime())) return null;

  const endOfCoverage = startOfDay(parsed);
  const today = startOfDay(now);

  const elapsed = Math.floor((today.getTime() - endOfCoverage.getTime()) / 86_400_000);
  const ageDays = Math.max(0, elapsed);

  return {
    ageDays,
    isRunningWeek: elapsed <= 0,
    // One publication may legitimately be outstanding: the DOE posts the
    // current week partway through it, so a region holding last week's report
    // is not behind. Two missed weeks is.
    status: ageDays <= WEEK_DAYS ? 'current' : ageDays <= WEEK_DAYS * 2 ? 'stale' : 'behind',
  };
}

function parseDate(value: string): Date {
  const [year, month, day] = value.slice(0, 10).split('-').map(Number);

  // A malformed date must not become "1 Jan 1970", which would read as decades
  // stale and look like a data disaster rather than a bad string.
  if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
    return new Date(NaN);
  }

  return new Date(year!, month! - 1, day!);
}

function startOfDay(value: Date): Date {
  return new Date(value.getFullYear(), value.getMonth(), value.getDate());
}

export function ageLabel(freshness: Freshness): string {
  if (freshness.isRunningWeek) return 'Current week';
  if (freshness.ageDays === 1) return '1 day';

  return `${freshness.ageDays} days`;
}

const STATUS_STYLES: Record<Freshness['status'], { label: string; className: string }> = {
  current: {
    label: 'Current',
    className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
  },
  stale: {
    label: 'One week behind',
    className: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  },
  behind: {
    label: 'Behind',
    className: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
  },
};

export function FreshnessBadge({ status }: { status: Freshness['status'] }) {
  const style = STATUS_STYLES[status];

  return (
    <span
      className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${style.className}`}
    >
      {style.label}
    </span>
  );
}

export function DataFreshness({ reports }: { reports: DoeReport[] }) {
  // Recomputed on mount rather than at module load, so a tab left open
  // overnight does not keep yesterday's arithmetic.
  const now = React.useMemo(() => new Date(), []);

  const rows = React.useMemo(
    () =>
      [...reports]
        .sort((a, b) => a.region.localeCompare(b.region))
        .map((report) => ({ report, freshness: freshnessOf(report, now) })),
    [reports, now],
  );

  const newestWeek = rows.reduce<string | null>(
    (newest, row) =>
      newest === null || row.report.coverage_start > newest ? row.report.coverage_start : newest,
    null,
  );

  return (
    <Card>
      <h3 className="mb-1 text-sm font-semibold text-slate-900 dark:text-slate-50">
        Data freshness
      </h3>
      <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
        Age is counted from the end of the week each report covers.
      </p>

      <div className="divide-y divide-slate-100 dark:divide-slate-800">
        {rows.map(({ report, freshness }) => (
          <div
            key={report.region}
            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3 first:pt-0 last:pb-0"
          >
            <div className="min-w-0">
              <p className="truncate text-sm font-medium text-slate-900 dark:text-slate-100">
                {report.region}
              </p>
              <p className="text-xs text-slate-500 dark:text-slate-400">{report.coverage_label}</p>
            </div>

            <div className="flex items-center gap-3">
              <span className="text-sm tabular-nums text-slate-700 dark:text-slate-300">
                {freshness ? ageLabel(freshness) : 'Unknown'}
              </span>
              {freshness ? <FreshnessBadge status={freshness.status} /> : null}
            </div>

            {/* Said out loud rather than left to be inferred from two coverage
                labels. A region a week behind the other is the reading most
                likely to be reported as a bug in the numbers. */}
            {newestWeek !== null && report.coverage_start < newestWeek ? (
              <p className="w-full text-xs text-amber-700 dark:text-amber-400">
                One publication behind the newest week held.
              </p>
            ) : null}
          </div>
        ))}
      </div>
    </Card>
  );
}

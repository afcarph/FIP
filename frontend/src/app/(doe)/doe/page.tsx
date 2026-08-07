'use client';

import { CalendarClock, CheckCircle2, Database, FileText, Globe2, Layers } from 'lucide-react';
import * as React from 'react';
import {
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { Card, ErrorState, LoadingState, QueryState, StatusBadge } from '@/components/doe/states';
import { useDoeImports, useDoeTrends, type TrendPoint } from '@/hooks/use-doe';

/** Grades charted on the dashboard, in the order the DOE lists them. */
const TRENDS = [
  { code: 'diesel', label: 'Diesel', colour: '#f59e0b' },
  { code: 'gasoline_ron91', label: 'RON 91', colour: '#22c55e' },
  { code: 'gasoline_ron95', label: 'RON 95', colour: '#0ea5e9' },
] as const;

function shortWeek(iso: string): string {
  return new Date(iso).toLocaleDateString('en-PH', { day: 'numeric', month: 'short' });
}

function StatCard({
  icon: Icon,
  label,
  value,
  hint,
}: {
  icon: React.ElementType;
  label: string;
  value: React.ReactNode;
  hint?: string;
}) {
  return (
    <Card>
      <div className="flex items-start gap-3">
        <div className="rounded-lg bg-emerald-50 p-2 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
          <Icon className="size-5" aria-hidden />
        </div>
        <div className="min-w-0">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
            {label}
          </p>
          <p className="mt-1 truncate text-xl font-semibold text-slate-900 dark:text-slate-50">
            {value}
          </p>
          {hint ? (
            <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{hint}</p>
          ) : null}
        </div>
      </div>
    </Card>
  );
}

/**
 * One grade's weekly series.
 *
 * Plots the published minimum and maximum as a band with the midpoint on top,
 * rather than the midpoint alone. The DOE publishes a range; drawing a single
 * line implies a precision the source does not have, and the spread between
 * cheapest and dearest is the number a driver actually acts on.
 */
function TrendChart({ code, label, colour }: (typeof TRENDS)[number]) {
  const { data, isLoading, error, refetch } = useDoeTrends(code, {}, 12);

  const points = React.useMemo(
    () =>
      (data ?? []).map((point: TrendPoint) => ({
        week: shortWeek(point.coverage_start),
        Lowest: point.lowest,
        Midpoint: point.midpoint,
        Highest: point.highest,
      })),
    [data],
  );

  return (
    <Card>
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-50">{label}</h3>
        <span className="text-xs text-slate-500 dark:text-slate-400">
          {points.length} week{points.length === 1 ? '' : 's'}
        </span>
      </div>

      <QueryState
        isLoading={isLoading}
        error={error}
        isEmpty={points.length === 0}
        onRetry={() => void refetch()}
        emptyTitle="No published weeks yet"
        emptyHint="Trends appear once at least one report has been imported for this grade."
        loadingRows={4}
      >
        <div className="h-48">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={points} margin={{ top: 4, right: 8, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-slate-200 dark:stroke-slate-800" />
              <XAxis dataKey="week" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
              <YAxis
                tick={{ fontSize: 11 }}
                tickLine={false}
                axisLine={false}
                domain={['dataMin - 2', 'dataMax + 2']}
                tickFormatter={(value: number) => `₱${Math.round(value)}`}
              />
              <Tooltip
                formatter={(value: number) => `₱${Number(value).toFixed(2)}`}
                contentStyle={{ fontSize: 12, borderRadius: 8 }}
              />
              {/* Three explicit series rather than a stacked band. Stacking the
                  spread on top of the minimum made the y-axis domain span the
                  *delta* as well as the prices, so it rendered from ₱-3. */}
              {/* Straight segments. The DOE publishes one figure a week, and a
                  curve would draw prices between those weeks that nobody
                  measured — a smoothing artefact read as data. */}
              <Line
                type="linear"
                dataKey="Lowest"
                stroke={colour}
                strokeWidth={1}
                strokeDasharray="4 3"
                dot={false}
              />
              <Line type="linear" dataKey="Midpoint" stroke={colour} strokeWidth={2} dot={false} />
              <Line
                type="linear"
                dataKey="Highest"
                stroke={colour}
                strokeWidth={1}
                strokeDasharray="4 3"
                dot={false}
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          Solid line is the midpoint of the DOE&apos;s published range; dashed lines bound it.
        </p>
      </QueryState>
    </Card>
  );
}

/** Weekly average across every grade, so the whole market is one glance. */
function WeeklyAverageChart() {
  const diesel = useDoeTrends('diesel', {}, 12);
  const ron91 = useDoeTrends('gasoline_ron91', {}, 12);
  const ron95 = useDoeTrends('gasoline_ron95', {}, 12);

  const isLoading = diesel.isLoading || ron91.isLoading || ron95.isLoading;
  const error = diesel.error ?? ron91.error ?? ron95.error;

  const merged = React.useMemo(() => {
    const byWeek = new Map<string, Record<string, number | string>>();

    const add = (points: TrendPoint[] | undefined, key: string) => {
      for (const point of points ?? []) {
        const week = point.coverage_start;
        const row = byWeek.get(week) ?? { week: shortWeek(week), sort: week };
        row[key] = point.midpoint;
        byWeek.set(week, row);
      }
    };

    add(diesel.data, 'Diesel');
    add(ron91.data, 'RON 91');
    add(ron95.data, 'RON 95');

    return [...byWeek.values()].sort((a, b) => String(a.sort).localeCompare(String(b.sort)));
  }, [diesel.data, ron91.data, ron95.data]);

  return (
    <Card className="lg:col-span-2">
      <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-slate-50">
        Weekly average prices
      </h3>

      <QueryState
        isLoading={isLoading}
        error={error}
        isEmpty={merged.length === 0}
        onRetry={() => {
          void diesel.refetch();
          void ron91.refetch();
          void ron95.refetch();
        }}
        emptyTitle="No weeks published yet"
        loadingRows={5}
      >
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={merged} margin={{ top: 4, right: 8, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-slate-200 dark:stroke-slate-800" />
              <XAxis dataKey="week" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
              <YAxis
                tick={{ fontSize: 11 }}
                tickLine={false}
                axisLine={false}
                domain={['dataMin - 3', 'dataMax + 3']}
                tickFormatter={(value: number) => `₱${Math.round(value)}`}
              />
              <Tooltip
                formatter={(value: number) => `₱${Number(value).toFixed(2)}`}
                contentStyle={{ fontSize: 12, borderRadius: 8 }}
              />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              {TRENDS.map((trend) => (
                <Line
                  key={trend.code}
                  type="linear"
                  dataKey={trend.label}
                  stroke={trend.colour}
                  strokeWidth={2}
                  dot={false}
                />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </div>
      </QueryState>
    </Card>
  );
}

export default function DoeDashboardPage() {
  const { data, isLoading, error, refetch } = useDoeImports();

  const regions = React.useMemo(
    () => (data ? [...new Set(data.latest_reports.map((report) => report.region))] : []),
    [data],
  );

  const newest = React.useMemo(() => {
    if (!data?.latest_reports.length) return null;

    return [...data.latest_reports].sort((a, b) =>
      b.coverage_start.localeCompare(a.coverage_start),
    )[0];
  }, [data]);

  const lastRun = data?.last_run;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-slate-50">Dashboard</h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Weekly fuel price monitoring published by the Department of Energy.
        </p>
      </div>

      {isLoading ? (
        <LoadingState label="Loading dashboard" rows={4} />
      ) : error ? (
        <Card>
          <ErrorState error={error} onRetry={() => void refetch()} />
        </Card>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard
              icon={FileText}
              label="Latest DOE report"
              value={newest ? newest.coverage_label : '—'}
              hint={newest ? newest.region : 'Nothing imported yet'}
            />
            <StatCard
              icon={Layers}
              label="Reports imported"
              value={(data?.reports_total ?? 0).toLocaleString()}
              hint={`${data?.latest_reports.length ?? 0} current, one per region`}
            />
            <StatCard
              icon={Database}
              label="Fuel price records"
              value={(data?.prices_total ?? 0).toLocaleString()}
              // Not "this week": records_total counts the newest report held
              // for each region, and those regions are not always on the same
              // week — NCR can lag Regions 6-8 by one publication.
              hint={`${(data?.records_total ?? 0).toLocaleString()} in the latest report per region`}
            />
            <StatCard
              icon={Globe2}
              label="Regions covered"
              value={data?.regions_total ?? regions.length}
              hint={regions.join(', ') || '—'}
            />
            <StatCard
              icon={CalendarClock}
              label="Last synchronisation"
              value={
                lastRun?.started_at
                  ? new Date(lastRun.started_at).toLocaleString('en-PH', {
                      dateStyle: 'medium',
                      timeStyle: 'short',
                    })
                  : '—'
              }
              hint={
                lastRun?.duration_seconds ? `Took ${Math.round(lastRun.duration_seconds)}s` : undefined
              }
            />
            <StatCard
              icon={CheckCircle2}
              label="Import status"
              value={lastRun ? <StatusBadge status={lastRun.status} /> : '—'}
              hint={
                lastRun
                  ? `${lastRun.reports_imported} imported · ${lastRun.reports_skipped} already held`
                  : undefined
              }
            />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <WeeklyAverageChart />
            {TRENDS.map((trend) => (
              <TrendChart key={trend.code} {...trend} />
            ))}
          </div>
        </>
      )}
    </div>
  );
}

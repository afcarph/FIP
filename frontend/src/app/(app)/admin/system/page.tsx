'use client';

import {
  Activity,
  Database,
  HardDrive,
  Clock,
  Search,
  Layers,
  AlertTriangle,
} from 'lucide-react';
import * as React from 'react';

import { Card, ErrorState, LoadingState } from '@/components/doe/states';
import { useSystemSnapshot, type CheckStatus, type SystemSnapshot } from '@/hooks/use-system';

/**
 * System health, for whoever is on call.
 *
 * Ordered by the question an operator actually asks at 3am — *can it serve,
 * and if not which part is broken* — rather than by how the data is stored.
 * Every number carries the unit and the threshold it is judged against, so
 * "1,240ms" does not need a second screen to interpret.
 */

const STATUS_STYLES: Record<CheckStatus, string> = {
  ok: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
  degraded: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  down: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
  unknown: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};

function StatusPill({ status, label }: { status: CheckStatus; label?: string }) {
  return (
    <span
      className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium uppercase tracking-wide ${STATUS_STYLES[status] ?? STATUS_STYLES.unknown}`}
    >
      {label ?? status}
    </span>
  );
}

function bytes(value: number | undefined): string {
  if (value === undefined) return '—';

  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let size = value;
  let unit = 0;

  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024;
    unit++;
  }

  return `${size.toFixed(size >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function ms(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    // Never "0ms". A phase that was not measured did not take no time, and
    // the runs written before the timings existed measured nothing.
    return 'not measured';
  }

  return value >= 1000 ? `${(value / 1000).toFixed(1)}s` : `${value}ms`;
}

function CheckCard({
  icon: Icon,
  title,
  status,
  rows,
}: {
  icon: React.ElementType;
  title: string;
  status: CheckStatus;
  rows: Array<[string, React.ReactNode]>;
}) {
  return (
    <Card>
      <div className="mb-3 flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Icon className="size-4 text-slate-500 dark:text-slate-400" aria-hidden />
          <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-50">{title}</h3>
        </div>
        <StatusPill status={status} />
      </div>

      <dl className="space-y-1.5 text-sm">
        {rows.map(([label, value]) => (
          <div key={label} className="flex items-baseline justify-between gap-3">
            <dt className="text-slate-500 dark:text-slate-400">{label}</dt>
            <dd className="truncate text-right font-medium tabular-nums text-slate-800 dark:text-slate-200">
              {value}
            </dd>
          </div>
        ))}
      </dl>
    </Card>
  );
}

function PhaseBars({ phases, total }: { phases: Record<string, number>; total: number | null | undefined }) {
  const entries = Object.entries(phases);

  if (entries.length === 0) {
    return (
      <p className="text-sm text-slate-500 dark:text-slate-400">
        This run predates per-phase timing. The next run will record it.
      </p>
    );
  }

  const measured = entries.reduce((sum, [, value]) => sum + value, 0);
  const widest = Math.max(...entries.map(([, value]) => value), 1);

  return (
    <div className="space-y-2">
      {entries.map(([phase, value]) => (
        <div key={phase} className="grid grid-cols-[7rem_1fr_5rem] items-center gap-2 text-sm">
          <span className="capitalize text-slate-600 dark:text-slate-300">{phase}</span>
          <span className="h-2 rounded-full bg-slate-100 dark:bg-slate-800">
            <span
              className="block h-2 rounded-full bg-emerald-500"
              style={{ width: `${Math.max((value / widest) * 100, 2)}%` }}
            />
          </span>
          <span className="text-right tabular-nums text-slate-700 dark:text-slate-300">
            {ms(value)}
          </span>
        </div>
      ))}

      {/* The gap between the phases and the wall clock is real, and hiding it
          would make the phases look like they account for everything. */}
      {total !== null && total !== undefined ? (
        <p className="pt-1 text-xs text-slate-500 dark:text-slate-400">
          {ms(total)} in total; {ms(total - measured)} outside the measured phases.
        </p>
      ) : null}
    </div>
  );
}

function Snapshot({ data }: { data: SystemSnapshot }) {
  const { checks, discovery, last_import: run, coverage } = data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-slate-50">System</h1>
        <StatusPill status={data.status} />
        <span className="text-xs text-slate-500 dark:text-slate-400">
          as of {new Date(data.time).toLocaleTimeString('en-PH')} · refreshes every 30s
        </span>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <CheckCard
          icon={Database}
          title="Database"
          status={checks.database?.status ?? 'unknown'}
          rows={[
            ['Latency', `${checks.database?.latency_ms ?? '—'} ms`],
            ['Driver', checks.database?.driver ?? '—'],
            ['Reports', (checks.database?.reports ?? 0).toLocaleString()],
          ]}
        />

        <CheckCard
          icon={Clock}
          title="Scheduler"
          status={checks.scheduler?.status ?? 'unknown'}
          rows={[
            [
              'Last run',
              checks.scheduler?.last_run_at
                ? new Date(checks.scheduler.last_run_at).toLocaleString('en-PH', {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                  })
                : '—',
            ],
            [
              'Age',
              checks.scheduler?.hours_since_last_run !== undefined
                ? `${checks.scheduler.hours_since_last_run}h of ${checks.scheduler.stale_after_hours}h`
                : '—',
            ],
            ['Outcome', checks.scheduler?.last_run_status ?? checks.scheduler?.detail ?? '—'],
          ]}
        />

        <CheckCard
          icon={HardDrive}
          title="Disk"
          status={checks.disk?.status ?? 'unknown'}
          rows={[
            ['Used', `${checks.disk?.used_percent ?? '—'}%`],
            ['Free', bytes(checks.disk?.free_bytes)],
            ['Total', bytes(checks.disk?.total_bytes)],
          ]}
        />

        <CheckCard
          icon={Layers}
          title="PDF archive"
          status={checks.storage?.status ?? 'unknown'}
          rows={[
            ['Files', (checks.storage?.files ?? 0).toLocaleString()],
            ['Size', bytes(checks.storage?.bytes)],
            ['Present', checks.storage?.exists ? 'yes' : 'no'],
          ]}
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <div className="mb-3 flex items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <Search className="size-4 text-slate-500 dark:text-slate-400" aria-hidden />
              <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-50">
                Discovery
              </h3>
            </div>
            <StatusPill status={discovery.status} />
          </div>

          <dl className="space-y-1.5 text-sm">
            <div className="flex items-baseline justify-between gap-3">
              <dt className="text-slate-500 dark:text-slate-400">Provider</dt>
              <dd className="font-medium text-slate-800 dark:text-slate-200">
                {discovery.provider}
              </dd>
            </div>
            <div className="flex items-baseline justify-between gap-3">
              <dt className="text-slate-500 dark:text-slate-400">Documents found</dt>
              <dd className="font-medium tabular-nums text-slate-800 dark:text-slate-200">
                {discovery.documents_discovered ?? '—'}
              </dd>
            </div>
            <div className="flex items-baseline justify-between gap-3">
              <dt className="text-slate-500 dark:text-slate-400">Parsed as reports</dt>
              <dd className="font-medium tabular-nums text-slate-800 dark:text-slate-200">
                {discovery.reports_parsed ?? '—'}
              </dd>
            </div>
            <div className="flex items-baseline justify-between gap-3">
              <dt className="text-slate-500 dark:text-slate-400">Library pages walked</dt>
              <dd className="font-medium tabular-nums text-slate-800 dark:text-slate-200">
                {discovery.pages_walked ?? '—'}
              </dd>
            </div>
          </dl>

          <p className="mt-3 break-all text-xs text-slate-500 dark:text-slate-400">
            {discovery.endpoint}
          </p>
          {/* A daily run should stay on page one. Creeping past it means the
              lookback window no longer covers the publication gap. */}
          {(discovery.pages_walked ?? 0) > 1 ? (
            <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">
              More than one page walked — check the lookback window still covers the gap between
              publications.
            </p>
          ) : null}
        </Card>

        <Card>
          <div className="mb-3 flex items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <Activity className="size-4 text-slate-500 dark:text-slate-400" aria-hidden />
              <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-50">
                Last import
              </h3>
            </div>
            <StatusPill status={run.healthy ? 'ok' : 'degraded'} label={run.status} />
          </div>

          <div className="mb-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-3">
            {(
              [
                ['Discovered', run.reports_discovered],
                ['Imported', run.reports_imported],
                ['Already held', run.reports_skipped],
                ['Rejected', run.reports_rejected],
                ['Rows written', run.rows_imported],
                ['Rows replaced', run.rows_updated],
              ] as const
            ).map(([label, value]) => (
              <div key={label}>
                <p className="text-xs text-slate-500 dark:text-slate-400">{label}</p>
                <p className="font-semibold tabular-nums text-slate-900 dark:text-slate-100">
                  {(value ?? 0).toLocaleString()}
                </p>
              </div>
            ))}
          </div>

          <PhaseBars phases={run.phase_durations_ms ?? {}} total={run.total_duration_ms} />
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-slate-50">
            Current coverage
          </h3>

          <div className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {coverage.regions.map((region) => (
              <div key={region.region} className="flex items-center justify-between gap-3 py-2">
                <div>
                  <p className="font-medium text-slate-900 dark:text-slate-100">{region.region}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    {region.coverage_start} → {region.coverage_end}
                  </p>
                </div>
                <span className="tabular-nums text-slate-600 dark:text-slate-300">
                  {region.is_current_week ? 'current week' : `${region.age_days}d old`}
                </span>
              </div>
            ))}
          </div>

          <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
            {coverage.reports_total.toLocaleString()} reports ·{' '}
            {coverage.prices_total.toLocaleString()} prices · back to{' '}
            {coverage.oldest_coverage_start ?? '—'}
          </p>
        </Card>

        <Card>
          <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-slate-50">
            Parser versions
          </h3>

          {Object.keys(data.parser_versions).length === 0 ? (
            <p className="text-sm text-slate-500 dark:text-slate-400">
              No recent run recorded which extractor it used.
            </p>
          ) : (
            <dl className="space-y-1.5 text-sm">
              {Object.entries(data.parser_versions).map(([parser, count]) => (
                <div key={parser} className="flex items-baseline justify-between gap-3">
                  <dt className="truncate text-slate-600 dark:text-slate-300">{parser}</dt>
                  <dd className="tabular-nums text-slate-800 dark:text-slate-200">
                    {count.toLocaleString()} reports
                  </dd>
                </div>
              ))}
            </dl>
          )}

          <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
            Across the last 20 runs. A report arriving from a different extractor than last week is
            the first sign of a layout change.
          </p>
        </Card>
      </div>

      {run.messages && run.messages.length > 0 ? (
        <Card>
          <div className="mb-2 flex items-center gap-2">
            <AlertTriangle className="size-4 text-amber-600" aria-hidden />
            <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-50">
              Last run messages
            </h3>
          </div>
          <p className="mb-2 text-xs text-slate-500 dark:text-slate-400">
            Documents the DOE publishes that this service cannot use — an LPG price sheet, a layout
            not yet supported. They recur every run and do not make a run unhealthy.
          </p>
          <pre className="max-h-64 overflow-auto rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-700 dark:bg-slate-900 dark:text-slate-300">
            {run.messages.join('\n')}
          </pre>
        </Card>
      ) : null}
    </div>
  );
}

export default function SystemPage() {
  const { data, isLoading, error, refetch } = useSystemSnapshot();

  if (isLoading) return <LoadingState label="Reading system health" rows={4} />;

  if (error || !data) {
    return (
      <Card>
        <ErrorState error={error} onRetry={() => void refetch()} />
      </Card>
    );
  }

  return <Snapshot data={data} />;
}

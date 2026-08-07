'use client';

import { Clock, Database, Search, Server } from 'lucide-react';
import * as React from 'react';

import { Card, ErrorState, LoadingState, StatusBadge } from '@/components/doe/states';
import { useDoeImports, type ImportRun } from '@/hooks/use-doe';

/** Seconds, or an em dash when the API does not report one. */
function seconds(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';

  return value >= 60 ? `${Math.floor(value / 60)}m ${Math.round(value % 60)}s` : `${value.toFixed(1)}s`;
}

function when(value: string | null | undefined): string {
  if (!value) return '—';

  return new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
}

function Row({
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
    <div className="flex items-start gap-3 border-b border-slate-100 py-3 last:border-0 dark:border-slate-800">
      <Icon className="mt-0.5 size-4 shrink-0 text-slate-400" aria-hidden />
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-slate-700 dark:text-slate-300">{label}</p>
        {hint ? <p className="text-xs text-slate-500 dark:text-slate-400">{hint}</p> : null}
      </div>
      <div className="text-right text-sm text-slate-900 dark:text-slate-100">{value}</div>
    </div>
  );
}

export default function DoeStatusPage() {
  const { data, isLoading, error, refetch, dataUpdatedAt } = useDoeImports();

  const lastRun = data?.last_run ?? null;
  const lastGood = data?.last_successful_run ?? null;
  const runs = data?.runs ?? [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-slate-50">API Status</h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Ingestion health. Refreshes every minute.
        </p>
      </div>

      {isLoading ? (
        <LoadingState label="Loading status" rows={5} />
      ) : error ? (
        <Card>
          <ErrorState error={error} onRetry={() => void refetch()} />
        </Card>
      ) : (
        <>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <h2 className="mb-1 text-sm font-semibold text-slate-900 dark:text-slate-50">
                Pipeline
              </h2>

              <Row
                icon={Search}
                label="Discovery"
                hint="Liferay GraphQL, prod-cms.doe.gov.ph"
                value={
                  <span className="text-emerald-700 dark:text-emerald-400">
                    {lastRun ? `${lastRun.pdfs_discovered} documents` : '—'}
                  </span>
                }
              />
              <Row
                icon={Database}
                label="Import"
                hint="Reports written to the platform"
                value={lastRun ? `${lastRun.reports_imported} imported` : '—'}
              />
              <Row
                icon={Server}
                label="Scheduler"
                hint="06:00 Asia/Manila, daily"
                value={
                  lastGood ? (
                    <span className="text-emerald-700 dark:text-emerald-400">Healthy</span>
                  ) : (
                    <span className="text-amber-600 dark:text-amber-400">No successful run</span>
                  )
                }
              />
              <Row icon={Clock} label="Last run" value={when(lastRun?.started_at)} />
            </Card>

            <Card>
              <h2 className="mb-1 text-sm font-semibold text-slate-900 dark:text-slate-50">
                Last run
              </h2>

              <Row
                icon={Clock}
                label="Status"
                value={lastRun ? <StatusBadge status={lastRun.status} /> : '—'}
              />
              <Row
                icon={Clock}
                label="Total duration"
                hint="Discovery, extraction and import combined"
                value={seconds(lastRun?.duration_seconds)}
              />
              <Row
                icon={Database}
                label="Records imported"
                value={(lastRun?.records_imported ?? 0).toLocaleString()}
              />
              <Row
                icon={Database}
                label="Already held"
                hint="Skipped on checksum, before extraction"
                value={lastRun?.reports_skipped ?? 0}
              />

              {/* Stated rather than shown as zeroes: the API records a total
                  only. Per-phase timings are a pending backend change, and
                  rendering three empty rows would read as a broken panel. */}
              <p className="mt-3 rounded-md bg-slate-50 p-2 text-xs text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                Discovery, extraction and import are not yet timed separately — the API records a
                single total. Per-phase timings are a pending backend change.
              </p>
            </Card>
          </div>

          <Card className="p-0">
            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
              <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-50">
                Recent runs
              </h2>
            </div>

            {runs.length === 0 ? (
              <p className="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                No runs recorded yet.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <tr>
                      <th className="px-4 py-2 font-medium">Started</th>
                      <th className="px-4 py-2 font-medium">Status</th>
                      <th className="px-4 py-2 font-medium">Duration</th>
                      <th className="px-4 py-2 font-medium">Found</th>
                      <th className="px-4 py-2 font-medium">Imported</th>
                      <th className="px-4 py-2 font-medium">Skipped</th>
                      <th className="px-4 py-2 font-medium">Rows</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {runs.map((run: ImportRun) => (
                      <tr key={run.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td className="whitespace-nowrap px-4 py-2 text-slate-700 dark:text-slate-300">
                          {when(run.started_at)}
                        </td>
                        <td className="whitespace-nowrap px-4 py-2">
                          <StatusBadge status={run.status} />
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-700 dark:text-slate-300">
                          {seconds(run.duration_seconds)}
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-700 dark:text-slate-300">
                          {run.pdfs_discovered}
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-700 dark:text-slate-300">
                          {run.reports_imported}
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-700 dark:text-slate-300">
                          {run.reports_skipped}
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-700 dark:text-slate-300">
                          {run.records_imported.toLocaleString()}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          {lastRun?.errors ? (
            <Card>
              <h2 className="mb-2 text-sm font-semibold text-slate-900 dark:text-slate-50">
                Last run messages
              </h2>
              <p className="mb-2 text-xs text-slate-500 dark:text-slate-400">
                Documents the DOE publishes that this service cannot use — an LPG price sheet, a
                layout not yet supported — are recorded here and rejected. They recur every run and
                do not make a run unhealthy.
              </p>
              <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded-md bg-slate-50 p-3 text-xs text-slate-700 dark:bg-slate-800/50 dark:text-slate-300">
                {lastRun.errors}
              </pre>
            </Card>
          ) : null}

          <p className="text-xs text-slate-500 dark:text-slate-400">
            Last refreshed {when(new Date(dataUpdatedAt).toISOString())}
          </p>
        </>
      )}
    </div>
  );
}

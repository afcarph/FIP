'use client';

import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Download, FileText } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
  downloadReportRun,
  queryKeys,
  useGenerateReport,
  useReportDefinitions,
  useReportRun,
  useReportRuns,
} from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';
import { formatDate, formatDateTime, formatNumber } from '@/lib/utils';
import type { ReportRun } from '@/types/api';

const PERIODS = [
  { value: 'daily', label: 'Today' },
  { value: 'weekly', label: 'This week' },
  { value: 'monthly', label: 'This month' },
  { value: 'annual', label: 'This year' },
] as const;

const FORMATS = [
  { value: 'pdf', label: 'PDF' },
  { value: 'xlsx', label: 'Excel' },
  { value: 'csv', label: 'CSV' },
  { value: 'json', label: 'JSON' },
] as const;

const STATUS: Record<ReportRun['status'], { label: string; variant: 'default' | 'secondary' | 'destructive' }> = {
  queued: { label: 'Queued', variant: 'secondary' },
  running: { label: 'Generating', variant: 'secondary' },
  completed: { label: 'Ready', variant: 'default' },
  failed: { label: 'Failed', variant: 'destructive' },
};

function fileSize(bytes: number | null): string {
  if (bytes === null) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * A generated report, with whatever it currently offers.
 *
 * The download is a fetch rather than a link because the API streams the file
 * behind the bearer token — a plain href would arrive without it and 401.
 */
function RunRow({ run }: { run: ReportRun }) {
  const [error, setError] = React.useState<string | null>(null);
  const [saving, setSaving] = React.useState(false);
  const badge = STATUS[run.status] ?? { label: run.status, variant: 'secondary' as const };

  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border px-4 py-3 last:border-0">
      <FileText className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium">{run.report ?? 'Report'}</span>
          <Badge variant={badge.variant}>{badge.label}</Badge>
          <span className="text-xs uppercase text-muted-foreground">{run.format}</span>
        </div>
        <p className="truncate text-sm text-muted-foreground">
          {run.period.from && run.period.to
            ? `${formatDate(run.period.from)} – ${formatDate(run.period.to)}`
            : 'No period'}
          {run.row_count !== null ? ` · ${formatNumber(run.row_count)} rows` : ''}
          {run.status === 'completed' ? ` · ${fileSize(run.file_size)}` : ''}
        </p>
        {/*
          A failed run keeps its reason. Showing "Failed" alone leaves the
          operator with nothing to act on or report.
        */}
        {run.error_message ? (
          <p className="mt-1 text-sm text-destructive">{run.error_message}</p>
        ) : null}
        {error ? <p className="mt-1 text-sm text-destructive">{error}</p> : null}
      </div>

      <div className="hidden w-40 shrink-0 text-sm text-muted-foreground sm:block">
        {run.completed_at ? formatDateTime(run.completed_at) : '—'}
      </div>

      <Button
        variant="ghost"
        size="sm"
        disabled={run.status !== 'completed' || saving}
        onClick={async () => {
          setError(null);
          setSaving(true);

          try {
            await downloadReportRun(run);
          } catch (cause) {
            // A file can expire or be swept between listing and clicking, and
            // the API says which — worth surfacing rather than failing mutely.
            setError(cause instanceof ApiError ? cause.message : 'The download failed.');
          } finally {
            setSaving(false);
          }
        }}
      >
        <Download className="h-4 w-4" aria-hidden />
        {saving ? 'Saving…' : 'Download'}
      </Button>
    </div>
  );
}

function ReportsPage() {
  const { data: definitions, isLoading: definitionsLoading } = useReportDefinitions();
  const { data: runs, isLoading: runsLoading } = useReportRuns();
  const generate = useGenerateReport();
  const queryClient = useQueryClient();

  const [code, setCode] = React.useState('');
  const [period, setPeriod] = React.useState<string>('monthly');
  const [format, setFormat] = React.useState<string>('pdf');

  // Anything over the sync row ceiling comes back queued, so that one run is
  // polled until it settles. Reports that render inline are already complete
  // and never enter this state.
  const [pendingId, setPendingId] = React.useState<number | null>(null);
  const { data: pending } = useReportRun(pendingId);

  /*
   * When the polled run settles, refresh the list as well as stopping.
   * Stopping alone left the row showing the status it had when the list was
   * last fetched — a finished report stuck on "Queued", and a failed one
   * never showing the reason it failed.
   */
  React.useEffect(() => {
    if (pending?.status !== 'completed' && pending?.status !== 'failed') return;

    setPendingId(null);
    queryClient.invalidateQueries({ queryKey: queryKeys.reportRuns() });
  }, [pending?.status, queryClient]);

  // Selected once the permission-filtered list arrives — which report is first
  // depends on the caller's role, so it cannot be hardcoded.
  React.useEffect(() => {
    const first = definitions?.[0];
    if (!code && first) setCode(first.code);
  }, [code, definitions]);

  const chosen = definitions?.find((definition) => definition.code === code);
  const error = generate.error instanceof ApiError ? generate.error : null;

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/fleet"
          className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Fleet
        </Link>
        <h1 className="text-2xl font-semibold">Reports</h1>
        <p className="text-sm text-muted-foreground">
          Generate a report over a period and download it. Files are kept for 30 days.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">New report</CardTitle>
        </CardHeader>
        <CardContent className="space-y-5">
          {definitionsLoading && <Skeleton className="h-24 w-full" />}

          {!definitionsLoading && (definitions ?? []).length === 0 && (
            <p className="text-sm text-muted-foreground">
              No reports are available to your account.
            </p>
          )}

          {(definitions ?? []).length > 0 && (
            <>
              <div className="grid gap-5 sm:grid-cols-3">
                <div className="space-y-2">
                  <Label htmlFor="code">Report</Label>
                  <select
                    id="code"
                    value={code}
                    onChange={(event) => setCode(event.target.value)}
                    className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                  >
                    {(definitions ?? []).map((definition) => (
                      <option key={definition.code} value={definition.code}>
                        {definition.name}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="period">Period</Label>
                  <select
                    id="period"
                    value={period}
                    onChange={(event) => setPeriod(event.target.value)}
                    className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                  >
                    {PERIODS.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="format">Format</Label>
                  <select
                    id="format"
                    value={format}
                    onChange={(event) => setFormat(event.target.value)}
                    className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                  >
                    {FORMATS.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {chosen?.description ? (
                <p className="text-sm text-muted-foreground">{chosen.description}</p>
              ) : null}

              {error ? <p className="text-sm text-destructive">{error.message}</p> : null}

              {pendingId !== null ? (
                <p className="text-sm text-muted-foreground">
                  This one is large enough to be generated in the background. It will appear below
                  when it is ready.
                </p>
              ) : null}

              <div className="flex justify-end">
                <Button
                  disabled={!code || generate.isPending}
                  onClick={async () => {
                    const run = await generate.mutateAsync({ code, period, format });

                    // Only a queued run needs polling; an inline one is already
                    // complete by the time it is returned.
                    if (run.status !== 'completed' && run.status !== 'failed') setPendingId(run.id);
                  }}
                >
                  {generate.isPending ? 'Generating…' : 'Generate'}
                </Button>
              </div>
            </>
          )}
        </CardContent>
      </Card>

      <div>
        <h2 className="mb-3 text-lg font-semibold">Recent reports</h2>

        {runsLoading && <Skeleton className="h-48 w-full" />}

        {!runsLoading && (runs ?? []).length === 0 && (
          <EmptyState
            icon={FileText}
            title="No reports yet"
            description="Generate one above and it will be listed here to download."
          />
        )}

        {(runs ?? []).length > 0 && (
          <Card>
            <CardContent className="p-0">
              {(runs ?? []).map((run) => (
                <RunRow key={run.id} run={run} />
              ))}
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  );
}

export default function Page() {
  return (
    <RequireRole roles={['fleet_manager', 'company_manager', 'super_admin', 'system_admin']}>
      <ReportsPage />
    </RequireRole>
  );
}

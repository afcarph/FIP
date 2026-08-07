'use client';

import { ExternalLink } from 'lucide-react';
import * as React from 'react';

import { Card, QueryState } from '@/components/doe/states';
import { useDoeReports, type DoeReport } from '@/hooks/use-doe';

/**
 * Every imported report.
 *
 * Reads `/fuel/reports` rather than the latest-per-region payload the
 * dashboard uses: a history view has to show every week held, and that is a
 * different question from "what is current".
 */

function QualityBadge({ quality }: { quality: number | null | undefined }) {
  if (quality === null || quality === undefined) {
    return <span className="text-slate-400">—</span>;
  }

  // The extractor's own confidence. Below 0.55 a report is rejected outright,
  // so anything stored here is at least that — the bands distinguish "read
  // perfectly" from "read, but worth a look".
  const tone =
    quality >= 0.95
      ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
      : quality >= 0.75
        ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'
        : 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300';

  return (
    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium tabular-nums ${tone}`}>
      {quality.toFixed(2)}
    </span>
  );
}

export default function DoeHistoryPage() {
  const { data, isLoading, error, refetch } = useDoeReports({ per_page: 100 });

  const reports = React.useMemo<DoeReport[]>(() => data?.reports ?? [], [data]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-slate-50">History</h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Every DOE publication imported, with the document it came from.
        </p>
      </div>

      <Card className="p-0">
        <QueryState
          isLoading={isLoading}
          error={error}
          isEmpty={reports.length === 0}
          onRetry={() => void refetch()}
          emptyTitle="No reports imported yet"
          emptyHint="The ingest runs at 06:00 Asia/Manila. Check API Status for the last run."
          loadingRows={6}
        >
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                <tr>
                  <th className="px-4 py-2 font-medium">Coverage</th>
                  <th className="px-4 py-2 font-medium">Region</th>
                  <th className="px-4 py-2 font-medium">Areas</th>
                  <th className="px-4 py-2 font-medium">Rows</th>
                  <th className="px-4 py-2 font-medium">Quality</th>
                  <th className="px-4 py-2 font-medium">Monitored</th>
                  <th className="px-4 py-2 font-medium">Source</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {reports.map((report) => (
                  <tr key={report.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <td className="whitespace-nowrap px-4 py-2 font-medium text-slate-900 dark:text-slate-100">
                      {report.coverage_label}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-slate-700 dark:text-slate-300">
                      {report.region}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-700 dark:text-slate-300">
                      {report.areas_count}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-700 dark:text-slate-300">
                      {report.rows_count.toLocaleString()}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2">
                      <QualityBadge quality={report.quality} />
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-500 dark:text-slate-400">
                      {report.monitoring_date ?? '—'}
                    </td>
                    <td className="px-4 py-2">
                      {report.source_url ? (
                        // The published PDF itself. A price with no provenance
                        // is a number someone has to take on trust.
                        <a
                          href={report.source_url}
                          target="_blank"
                          rel="noreferrer noopener"
                          className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                        >
                          Open PDF
                          <ExternalLink className="size-3" aria-hidden />
                        </a>
                      ) : (
                        <span className="text-xs text-slate-400">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </Card>

      <p className="text-xs text-slate-500 dark:text-slate-400">
        {data?.page ? `${data.page.total} report${data.page.total === 1 ? '' : 's'} imported.` : null}{' '}
        Prices for any of these weeks are queryable through Search using the coverage week filter.
      </p>
    </div>
  );
}

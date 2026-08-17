'use client';

import { AlertTriangle, CheckCircle2, ShieldQuestion, Trash2 } from 'lucide-react';
import * as React from 'react';

import { Card, ErrorState, LoadingState } from '@/components/doe/states';
import {
  usePrivacySettings,
  useUpdateLocationRetention,
  type LocationRetention,
  type RetentionStatus,
} from '@/hooks/use-settings';

/**
 * Privacy and data retention.
 *
 * The page exists because a retention period held only in a `.env` is a
 * decision nobody can be seen to have made. The status badge is the point of
 * the screen: it distinguishes a period somebody approved from a fallback the
 * platform is merely running on, even when the two are the same number.
 */

const STATUS: Record<
  RetentionStatus,
  { label: string; className: string; icon: typeof CheckCircle2; detail: string }
> = {
  approved: {
    label: 'Approved',
    className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    icon: CheckCircle2,
    detail: 'An administrator set this period deliberately.',
  },
  provisional: {
    label: 'Provisional',
    className: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    icon: AlertTriangle,
    detail:
      'No administrator has set a period. The platform is running on the installation fallback, which has not been approved by the business.',
  },
  requires_review: {
    label: 'Requires review',
    className: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
    icon: ShieldQuestion,
    detail:
      'The configured period and the environment fallback disagree. The configured period is the one in force; the environment value is being ignored.',
  },
};

function StatusBadge({ status }: { status: RetentionStatus }) {
  const meta = STATUS[status];
  const Icon = meta.icon;

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium uppercase tracking-wide ${meta.className}`}
    >
      <Icon className="h-3.5 w-3.5" />
      {meta.label}
    </span>
  );
}

function RetentionForm({ retention }: { retention: LocationRetention }) {
  const update = useUpdateLocationRetention();
  const [days, setDays] = React.useState(String(retention.days));

  // Re-sync when the server value changes underneath the form, otherwise a
  // successful save leaves the input showing what was typed rather than what
  // was stored.
  React.useEffect(() => setDays(String(retention.days)), [retention.days]);

  const parsed = Number(days);
  const valid =
    Number.isInteger(parsed) && parsed >= retention.minimum_days && parsed <= retention.maximum_days;
  const dirty = parsed !== retention.days;
  const meta = STATUS[retention.status];

  return (
    <form
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault();
        if (valid && dirty) update.mutate(parsed);
      }}
    >
      <div className="flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1.5">
          <span className="text-sm font-medium">Location retention period</span>
          <span className="flex items-center gap-2">
            <input
              type="number"
              inputMode="numeric"
              min={retention.minimum_days}
              max={retention.maximum_days}
              value={days}
              onChange={(event) => setDays(event.target.value)}
              className="w-28 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
              aria-describedby="retention-help"
            />
            <span className="text-sm text-slate-600 dark:text-slate-400">days</span>
          </span>
        </label>

        <button
          type="submit"
          disabled={!valid || !dirty || update.isPending}
          className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-40 dark:bg-slate-100 dark:text-slate-900"
        >
          {update.isPending ? 'Saving…' : 'Save period'}
        </button>
      </div>

      {!valid && days !== '' && (
        <p className="text-sm text-rose-600 dark:text-rose-400">
          Enter a whole number between {retention.minimum_days} and {retention.maximum_days} days.
          Pruning cannot be switched off from here.
        </p>
      )}

      {update.isError && (
        <p className="text-sm text-rose-600 dark:text-rose-400">
          {(update.error as Error).message}
        </p>
      )}

      {update.isSuccess && !dirty && (
        <p className="text-sm text-emerald-700 dark:text-emerald-400">
          Saved. The change is recorded in the audit log.
        </p>
      )}

      <p id="retention-help" className="text-sm text-slate-600 dark:text-slate-400">
        Vehicle location history older than this period is deleted automatically by the scheduled
        retention job. This controls location history only — fill-up records and their coordinates
        are kept under their own financial retention policy.
      </p>

      <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm dark:border-slate-800 dark:bg-slate-900/50">
        <p className="text-slate-700 dark:text-slate-300">{meta.detail}</p>
        {retention.status !== 'approved' && (
          <p className="mt-2 text-slate-600 dark:text-slate-400">
            This period requires business and privacy approval before location tracking runs on real
            drivers. See the privacy impact assessment.
          </p>
        )}
      </div>
    </form>
  );
}

function Impact({ retention }: { retention: LocationRetention }) {
  return (
    <Card>
      <div className="space-y-3 p-4">
        <h3 className="flex items-center gap-2 text-sm font-semibold">
          <Trash2 className="h-4 w-4" />
          What the next prune removes
        </h3>

        <dl className="grid grid-cols-2 gap-3 text-sm">
          <div>
            <dt className="text-slate-600 dark:text-slate-400">Location rows stored</dt>
            <dd className="text-lg font-semibold tabular-nums">
              {retention.stored_rows.toLocaleString()}
            </dd>
          </div>
          <div>
            <dt className="text-slate-600 dark:text-slate-400">Older than {retention.days} days</dt>
            <dd className="text-lg font-semibold tabular-nums">
              {retention.rows_beyond_retention.toLocaleString()}
            </dd>
          </div>
        </dl>

        <p className="text-sm text-slate-600 dark:text-slate-400">
          {retention.rows_beyond_retention > 0
            ? 'These rows are deleted permanently on the next scheduled run. Shortening the period increases this count.'
            : 'Nothing currently falls outside the retention period.'}
        </p>
      </div>
    </Card>
  );
}

export default function PrivacySettingsPage() {
  const { data, isLoading, error, refetch } = usePrivacySettings();

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Privacy &amp; data retention</h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Settings that decide what the platform keeps and what it deletes. Changes are audited.
        </p>
      </div>

      {isLoading && <LoadingState label="Loading settings" />}
      {error && <ErrorState error={error} onRetry={() => refetch()} />}

      {data && (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
          <Card>
            <div className="space-y-4 p-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-semibold">Vehicle location retention</h2>
                <StatusBadge status={data.location_retention.status} />
              </div>

              <RetentionForm retention={data.location_retention} />
            </div>
          </Card>

          <Impact retention={data.location_retention} />
        </div>
      )}
    </div>
  );
}

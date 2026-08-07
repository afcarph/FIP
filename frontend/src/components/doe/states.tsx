'use client';

import { AlertTriangle, Inbox, RefreshCw, WifiOff } from 'lucide-react';
import * as React from 'react';

import { ApiError } from '@/lib/api-client';
import { cn } from '@/lib/utils';

/**
 * The four states every panel in this section can be in.
 *
 * Kept together because the distinction between them is the point. An empty
 * result and a failed request look identical if both render "no data", and a
 * tester cannot tell whether they have found a bug or a quiet week.
 */

export function Card({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900',
        className,
      )}
    >
      {children}
    </div>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return (
    <div
      className={cn('animate-pulse rounded bg-slate-200 dark:bg-slate-800', className)}
      aria-hidden
    />
  );
}

export function LoadingState({ label = 'Loading', rows = 3 }: { label?: string; rows?: number }) {
  return (
    <div role="status" aria-live="polite" className="space-y-2">
      <span className="sr-only">{label}</span>
      {Array.from({ length: rows }).map((_, index) => (
        <Skeleton key={index} className="h-9 w-full" />
      ))}
    </div>
  );
}

export function EmptyState({
  title = 'Nothing to show',
  hint,
}: {
  title?: string;
  hint?: string;
}) {
  return (
    <div className="flex flex-col items-center gap-2 py-10 text-center">
      <Inbox className="size-8 text-slate-400" aria-hidden />
      <p className="text-sm font-medium text-slate-700 dark:text-slate-200">{title}</p>
      {hint ? <p className="max-w-sm text-xs text-slate-500 dark:text-slate-400">{hint}</p> : null}
    </div>
  );
}

/**
 * A failed request.
 *
 * Distinguishes losing the network from the API answering with an error,
 * because the remedy is different and a UAT tester needs to be able to say
 * which one they saw.
 */
export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const offline = typeof navigator !== 'undefined' && !navigator.onLine;
  const apiError = error instanceof ApiError ? error : null;

  const Icon = offline ? WifiOff : AlertTriangle;
  const title = offline ? 'You appear to be offline' : 'The API could not be reached';

  const detail = offline
    ? 'Reconnect and try again — nothing has been lost.'
    : apiError
      ? `${apiError.status ? `HTTP ${apiError.status} — ` : ''}${apiError.message}`
      : error instanceof Error
        ? error.message
        : 'Unknown error';

  return (
    <div className="flex flex-col items-center gap-3 py-10 text-center">
      <Icon className="size-8 text-amber-500" aria-hidden />
      <div>
        <p className="text-sm font-medium text-slate-800 dark:text-slate-100">{title}</p>
        <p className="mt-1 max-w-md text-xs text-slate-500 dark:text-slate-400">{detail}</p>
      </div>
      {onRetry ? (
        <button
          type="button"
          onClick={onRetry}
          className="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        >
          <RefreshCw className="size-3.5" aria-hidden />
          Retry
        </button>
      ) : null}
    </div>
  );
}

/**
 * Chooses between the four states so a page does not repeat the ladder.
 *
 * `isEmpty` is passed rather than inferred: only the caller knows whether an
 * empty array is "no results for this filter" or "nothing imported yet".
 */
export function QueryState({
  isLoading,
  error,
  isEmpty,
  onRetry,
  emptyTitle,
  emptyHint,
  loadingRows,
  children,
}: {
  isLoading: boolean;
  error: unknown;
  isEmpty: boolean;
  onRetry?: () => void;
  emptyTitle?: string;
  emptyHint?: string;
  loadingRows?: number;
  children: React.ReactNode;
}) {
  if (isLoading) return <LoadingState rows={loadingRows} />;
  if (error) return <ErrorState error={error} onRetry={onRetry} />;
  if (isEmpty) return <EmptyState title={emptyTitle} hint={emptyHint} />;

  return <>{children}</>;
}

const STATUS_STYLES: Record<string, string> = {
  success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
  no_changes: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
  partial: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  failed: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
  running: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};

/** Run statuses read as words, not colours alone. */
export function StatusBadge({ status }: { status: string }) {
  const label = status.replace('_', ' ');

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize',
        STATUS_STYLES[status] ?? STATUS_STYLES.running,
      )}
    >
      {label}
    </span>
  );
}

/** A published min–max range. Never collapsed to one number. */
export function PriceRange({ min, max }: { min: number | null; max: number | null }) {
  if (min === null && max === null) return <span className="text-slate-400">—</span>;
  if (min !== null && max !== null && min !== max) {
    return (
      <span className="tabular-nums">
        ₱{min.toFixed(2)} – ₱{max.toFixed(2)}
      </span>
    );
  }

  return <span className="tabular-nums">₱{(min ?? max)!.toFixed(2)}</span>;
}

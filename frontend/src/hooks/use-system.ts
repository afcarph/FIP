'use client';

import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/api-client';

/**
 * The operator's view of the platform.
 *
 * Served from the same service as `/api/v1/health`, so the dashboard and the
 * load balancer cannot disagree — a page reading "degraded" while the probe
 * returns 200 is worse than either signal on its own.
 */

export type CheckStatus = 'ok' | 'degraded' | 'down' | 'unknown';

export interface SystemCheck {
  status: CheckStatus;
  [key: string]: unknown;
}

export interface SystemSnapshot {
  status: CheckStatus;
  checks: {
    database?: SystemCheck & { latency_ms?: number; driver?: string; reports?: number };
    scheduler?: SystemCheck & {
      last_run_at?: string;
      hours_since_last_run?: number;
      stale_after_hours?: number;
      last_run_status?: string;
      detail?: string;
    };
    disk?: SystemCheck & { free_bytes?: number; total_bytes?: number; used_percent?: number };
    storage?: SystemCheck & { path?: string; exists?: boolean; files?: number; bytes?: number };
  };
  discovery: {
    provider: string;
    endpoint: string;
    status: CheckStatus;
    documents_discovered: number | null;
    pages_walked: number | null;
    reports_parsed: number | null;
  };
  last_import: {
    status: string;
    healthy?: boolean;
    started_at?: string;
    finished_at?: string | null;
    run_id?: string | null;
    reports_discovered?: number;
    reports_imported?: number;
    reports_skipped?: number;
    reports_rejected?: number;
    rows_imported?: number;
    rows_updated?: number;
    total_duration_ms?: number | null;
    phase_durations_ms?: Record<string, number>;
    last_successful_run_at?: string | null;
    messages?: string[];
  };
  coverage: {
    reports_total: number;
    prices_total: number;
    oldest_coverage_start: string | null;
    regions: Array<{
      region: string;
      coverage_start: string;
      coverage_end: string;
      age_days: number;
      is_current_week: boolean;
    }>;
  };
  parser_versions: Record<string, number>;
  time: string;
}

export function useSystemSnapshot() {
  return useQuery({
    queryKey: ['admin', 'system'],
    queryFn: async () => {
      const response = await api.get<SystemSnapshot>('/admin/system');

      return response.data;
    },
    // An operator watching a deploy wants this to move without a reload; a
    // minute is often enough for a run to start and finish.
    refetchInterval: 30_000,
  });
}

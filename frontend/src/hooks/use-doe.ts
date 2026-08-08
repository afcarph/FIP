'use client';

import { useQuery } from '@tanstack/react-query';

import { api, type Pagination } from '@/lib/api-client';

/**
 * The envelope nests pagination under `meta.pagination`, alongside
 * `request_id` and `timestamp`. Reading `meta` as the page itself yields an
 * undefined total, which then silently falls back to the number of rows on
 * screen — a result count that always equals the page size.
 */
function pageOf(meta: unknown): Pagination | undefined {
  return (meta as { pagination?: Pagination } | undefined)?.pagination;
}

/**
 * Hooks over the DOE price monitoring endpoints.
 *
 * These are the department's own weekly publications: a min–max range per
 * city, product and brand, plus the common price it states outright. They are
 * not per-station pump prices — that is `/stations` and `/prices`, which the
 * platform maintains separately. Nothing here is interchangeable with those.
 */

export interface DoeReport {
  id: number;
  region: string;
  coverage_start: string;
  coverage_end: string;
  coverage_label: string;
  monitoring_date: string | null;
  publication_date: string | null;
  source_url: string | null;
  checksum: string;
  areas_count: number;
  rows_count: number;
  extractor?: string | null;
  quality?: number | null;
}

export interface DoePrice {
  id: number;
  area: string;
  province: string | null;
  product: string;
  fuel_code: string | null;
  /** Null on the row carrying the area's overall range and common price. */
  brand: string | null;
  is_overall: boolean;
  min_price: number | null;
  max_price: number | null;
  common_price: number | null;
  report?: DoeReport;
}

export interface TrendPoint {
  coverage_start: string;
  coverage_end: string;
  areas: number;
  lowest: number;
  highest: number;
  /** Midpoint of the published range — see the note the API returns with it. */
  midpoint: number;
  common: number | null;
}

export interface ImportRun {
  id: number;
  started_at: string;
  finished_at: string | null;
  duration_seconds: number | null;
  pdfs_discovered: number;
  pdfs_downloaded: number;
  reports_imported: number;
  reports_skipped: number;
  records_imported: number;
  status: 'running' | 'success' | 'partial' | 'failed' | 'no_changes';
  errors: string | null;
}

export interface ImportHealth {
  last_run: ImportRun | null;
  last_successful_run: ImportRun | null;
  last_import_duration_seconds: number | null;
  failed_runs: ImportRun[];
  latest_reports: DoeReport[];
  latest_publication_date: string | null;
  /** Everything held, not just the current week. */
  reports_total: number;
  prices_total: number;
  regions_total: number;
  oldest_coverage_date: string | null;
  /** Rows in the latest report per region — what is on screen now. */
  records_total: number;
  runs: ImportRun[];
}

export interface AreaSummary {
  area: string;
  region: string;
  reports: number;
}

/**
 * The platform's fuel codes with the DOE's own product labels.
 *
 * Fixed by `fuel_types` rather than derived from whatever the current page
 * happens to contain, so the filter options do not change as you filter.
 */
export const FUEL_TYPE_OPTIONS = [
  { code: 'gasoline_ron91', label: 'RON 91' },
  { code: 'gasoline_ron95', label: 'RON 95' },
  { code: 'gasoline_ron97', label: 'RON 97' },
  { code: 'gasoline_ron100', label: 'RON 100' },
  { code: 'diesel', label: 'Diesel' },
  { code: 'diesel_premium', label: 'Diesel Plus' },
  { code: 'kerosene', label: 'Kerosene' },
] as const;

export interface BrandSummary {
  brand: string;
  areas: number;
  products: number;
}

export interface DoeFilters {
  region?: string;
  area?: string;
  province?: string;
  brand?: string;
  product?: string;
  fuel_code?: string;
  min_price?: string;
  max_price?: string;
  date_from?: string;
  date_to?: string;
  branded_only?: string;
  per_page?: number;
}

export const doeKeys = {
  latest: (filters: DoeFilters) => ['doe', 'latest', filters] as const,
  history: (filters: DoeFilters) => ['doe', 'history', filters] as const,
  areas: (region?: string) => ['doe', 'areas', region ?? 'all'] as const,
  brands: (region?: string) => ['doe', 'brands', region ?? 'all'] as const,
  search: (filters: DoeFilters) => ['doe', 'search', filters] as const,
  trends: (fuelCode: string, filters: DoeFilters) => ['doe', 'trends', fuelCode, filters] as const,
  imports: ['doe', 'imports'] as const,
  reports: (filters: DoeFilters) => ['doe', 'reports', filters] as const,
};

/** Strip empty values so they do not become `?region=` in the query string. */
function clean(filters: DoeFilters): Record<string, string | number> {
  return Object.fromEntries(
    Object.entries(filters).filter(
      (entry): entry is [string, string | number] =>
        entry[1] !== undefined && entry[1] !== '' && entry[1] !== null,
    ),
  );
}

export function useDoeLatest(filters: DoeFilters = {}) {
  return useQuery({
    queryKey: doeKeys.latest(filters),
    queryFn: async () => {
      const response = await api.get<DoePrice[]>('/fuel/latest', clean(filters));

      return {
        prices: response.data,
        page: pageOf(response.meta),
        reports: (response.meta as { reports?: DoeReport[] } | undefined)?.reports,
      };
    },
    // The DOE publishes weekly and the ingest runs daily, so anything shorter
    // just re-fetches the same rows.
    staleTime: 5 * 60_000,
  });
}

export function useDoeHistory(filters: DoeFilters = {}) {
  return useQuery({
    queryKey: doeKeys.history(filters),
    queryFn: async () => {
      const response = await api.get<DoePrice[]>('/fuel/history', clean(filters));

      return { prices: response.data, page: pageOf(response.meta) };
    },
    staleTime: 5 * 60_000,
  });
}

export function useDoeSearch(filters: DoeFilters, enabled = true) {
  return useQuery({
    queryKey: doeKeys.search(filters),
    queryFn: async () => {
      const response = await api.get<DoePrice[]>('/fuel/search', clean(filters));

      return { prices: response.data, page: pageOf(response.meta) };
    },
    enabled,
    // Keeps the previous rows on screen while a filter change is in flight, so
    // the table does not blank out on every keystroke.
    placeholderData: (previous) => previous,
    staleTime: 60_000,
  });
}

export function useDoeAreas(region?: string) {
  return useQuery({
    queryKey: doeKeys.areas(region),
    queryFn: async () => (await api.get<AreaSummary[]>('/fuel/areas', clean({ region }))).data,
    staleTime: 10 * 60_000,
  });
}

export function useDoeBrands(region?: string) {
  return useQuery({
    queryKey: doeKeys.brands(region),
    queryFn: async () => (await api.get<BrandSummary[]>('/fuel/brands', clean({ region }))).data,
    staleTime: 10 * 60_000,
  });
}

export function useDoeTrends(fuelCode: string, filters: DoeFilters = {}, weeks = 12) {
  return useQuery({
    queryKey: doeKeys.trends(fuelCode, { ...filters, per_page: weeks }),
    queryFn: async () =>
      (await api.get<TrendPoint[]>('/fuel/trends', { ...clean(filters), fuel_code: fuelCode, weeks }))
        .data,
    enabled: Boolean(fuelCode),
    staleTime: 5 * 60_000,
  });
}

/** Every imported report, newest week first. */
export function useDoeReports(filters: DoeFilters = {}) {
  return useQuery({
    queryKey: doeKeys.reports(filters),
    queryFn: async () => {
      const response = await api.get<DoeReport[]>('/fuel/reports', clean(filters));

      return { reports: response.data, page: pageOf(response.meta) };
    },
    staleTime: 5 * 60_000,
  });
}

export function useDoeImports() {
  return useQuery({
    queryKey: doeKeys.imports,
    queryFn: async () => (await api.get<ImportHealth>('/fuel/imports')).data,
    // The ingest runs at 06:00; a minute is enough to watch a run land without
    // hammering the endpoint.
    staleTime: 60_000,
    refetchInterval: 60_000,
  });
}

/** Every distinct region across the latest reports, for the filter controls. */
export function useDoeRegions() {
  const { data, ...rest } = useDoeImports();

  return {
    ...rest,
    data: data ? [...new Set(data.latest_reports.map((report) => report.region))].sort() : undefined,
  };
}

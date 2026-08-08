'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api-client';
import type {
  Advisory,
  AppNotification,
  AssistantReply,
  CheapestStation,
  DashboardData,
  ExecutiveDashboard,
  ExpenseSummary,
  FleetDashboard,
  Forecast,
  FuelPurchase,
  FuelReading,
  FuelReadingHistory,
  FuelReadingResult,
  FuelType,
  HeatMapCell,
  MonthlyPoint,
  PriceComparison,
  ReceiptScanResult,
  RefuelRecommendation,
  RegionalMovement,
  SavingsAnalysis,
  Station,
  TrendPoint,
  User,
  Vehicle,
  VehicleEfficiency,
} from '@/types/api';

/**
 * Query keys are declared in one place so an invalidation after a mutation
 * cannot silently miss a cache entry.
 */
export const queryKeys = {
  dashboard: ['dashboard'] as const,
  fleetDashboard: (fleetId?: number) => ['dashboard', 'fleet', fleetId] as const,
  executive: ['dashboard', 'executive'] as const,
  fuelTypes: ['fuel-types'] as const,
  stations: (filters: Record<string, unknown>) => ['stations', filters] as const,
  nearby: (lat: number, lng: number, radius: number, fuelTypeId?: number) =>
    ['stations', 'nearby', lat.toFixed(3), lng.toFixed(3), radius, fuelTypeId] as const,
  cheapest: (lat: number, lng: number, fuelTypeId: number) =>
    ['stations', 'cheapest', lat.toFixed(3), lng.toFixed(3), fuelTypeId] as const,
  station: (slug: string) => ['stations', slug] as const,
  comparison: (cityId?: number) => ['prices', 'comparison', cityId] as const,
  trend: (fuelTypeId: number, days: number) => ['prices', 'trend', fuelTypeId, days] as const,
  advisories: (fuelTypeId: number, weeks: number) => ['prices', 'advisories', fuelTypeId, weeks] as const,
  heatMap: (fuelTypeId?: number) => ['prices', 'heat-map', fuelTypeId] as const,
  regional: (fuelTypeId: number) => ['prices', 'regional', fuelTypeId] as const,
  forecasts: ['forecasts'] as const,
  vehicles: (filters?: Record<string, unknown>) => ['vehicles', filters] as const,
  vehicle: (id: number) => ['vehicles', id] as const,
  vehicleEfficiency: (id: number) => ['vehicles', id, 'efficiency'] as const,
  fuelReadings: (id: number, filters?: Record<string, unknown>) =>
    ['vehicles', id, 'fuel-readings', filters] as const,
  expenses: (filters: Record<string, unknown>) => ['expenses', filters] as const,
  expenseSummary: (filters: Record<string, unknown>) => ['expenses', 'summary', filters] as const,
  notifications: (unread: boolean) => ['notifications', unread] as const,
};

const FIVE_MINUTES = 5 * 60 * 1000;

// ------------------------------------------------------------ dashboards ---

export function useDashboard() {
  return useQuery({
    queryKey: queryKeys.dashboard,
    queryFn: async () => (await api.get<DashboardData>('/dashboard')).data,
    staleTime: 60_000,
  });
}

export function useFleetDashboard(fleetId?: number) {
  return useQuery({
    queryKey: queryKeys.fleetDashboard(fleetId),
    queryFn: async () =>
      (await api.get<FleetDashboard>('/fleet/dashboard', { fleet_id: fleetId })).data,
    staleTime: 60_000,
  });
}

export function useExecutiveDashboard() {
  return useQuery({
    queryKey: queryKeys.executive,
    queryFn: async () => (await api.get<ExecutiveDashboard>('/dashboard/executive')).data,
    staleTime: FIVE_MINUTES,
  });
}

// ---------------------------------------------------------------- prices ---

export function useFuelTypes() {
  return useQuery({
    queryKey: queryKeys.fuelTypes,
    queryFn: async () => (await api.get<FuelType[]>('/prices/fuel-types')).data,
    // Reference data: effectively immutable within a session.
    staleTime: Infinity,
  });
}

export function usePriceComparison(cityId?: number) {
  return useQuery({
    queryKey: queryKeys.comparison(cityId),
    queryFn: async () =>
      (await api.get<PriceComparison[]>('/prices/comparison', { city_id: cityId })).data,
    staleTime: FIVE_MINUTES,
  });
}

export function usePriceTrend(fuelTypeId: number | undefined, days = 90) {
  return useQuery({
    queryKey: queryKeys.trend(fuelTypeId ?? 0, days),
    queryFn: async () =>
      (await api.get<TrendPoint[]>('/prices/trend', { fuel_type_id: fuelTypeId, days })).data,
    enabled: Boolean(fuelTypeId),
    staleTime: FIVE_MINUTES,
  });
}

export function useAdvisories(fuelTypeId: number | undefined, weeks = 12) {
  return useQuery({
    queryKey: queryKeys.advisories(fuelTypeId ?? 0, weeks),
    queryFn: async () =>
      (await api.get<Advisory[]>('/prices/advisories', { fuel_type_id: fuelTypeId, weeks })).data,
    enabled: Boolean(fuelTypeId),
    staleTime: FIVE_MINUTES,
  });
}

export function useHeatMap(fuelTypeId?: number) {
  return useQuery({
    queryKey: queryKeys.heatMap(fuelTypeId),
    queryFn: async () =>
      (await api.get<HeatMapCell[]>('/prices/heat-map', { fuel_type_id: fuelTypeId })).data,
    staleTime: FIVE_MINUTES,
  });
}

export function useRegionalMovement(fuelTypeId: number | undefined) {
  return useQuery({
    queryKey: queryKeys.regional(fuelTypeId ?? 0),
    queryFn: async () =>
      (await api.get<RegionalMovement[]>('/prices/regional-movement', { fuel_type_id: fuelTypeId })).data,
    enabled: Boolean(fuelTypeId),
    staleTime: FIVE_MINUTES,
  });
}

export function useForecasts() {
  return useQuery({
    queryKey: queryKeys.forecasts,
    queryFn: async () => (await api.get<Forecast[]>('/forecasts')).data,
    staleTime: 30 * 60 * 1000,   // regenerated weekly; no need to poll
  });
}

// -------------------------------------------------------------- stations ---

export function useNearbyStations(
  lat: number,
  lng: number,
  radiusKm = 5,
  fuelTypeId?: number,
  enabled = true,
) {
  return useQuery({
    queryKey: queryKeys.nearby(lat, lng, radiusKm, fuelTypeId),
    queryFn: async () =>
      (
        await api.get<Station[]>('/stations/nearby', {
          latitude: lat,
          longitude: lng,
          radius_km: radiusKm,
          fuel_type_id: fuelTypeId,
        })
      ).data,
    enabled: enabled && Number.isFinite(lat) && Number.isFinite(lng),
    staleTime: 2 * 60 * 1000,
  });
}

export function useCheapestStations(lat: number, lng: number, fuelTypeId: number | undefined) {
  return useQuery({
    queryKey: queryKeys.cheapest(lat, lng, fuelTypeId ?? 0),
    queryFn: async () =>
      (
        await api.get<CheapestStation[]>('/stations/cheapest', {
          latitude: lat,
          longitude: lng,
          fuel_type_id: fuelTypeId,
          limit: 10,
        })
      ).data,
    enabled: Boolean(fuelTypeId) && Number.isFinite(lat),
    staleTime: 2 * 60 * 1000,
  });
}

export function useStation(slug: string) {
  return useQuery({
    queryKey: queryKeys.station(slug),
    queryFn: async () => (await api.get<Station>(`/stations/${slug}`)).data,
    enabled: Boolean(slug),
  });
}

// -------------------------------------------------------------- vehicles ---

export function useVehicles(filters: Record<string, unknown> = {}) {
  return useQuery({
    queryKey: queryKeys.vehicles(filters),
    queryFn: async () => (await api.get<Vehicle[]>('/vehicles', filters as never)).data,
  });
}

export function useVehicle(id: number | undefined) {
  return useQuery({
    queryKey: queryKeys.vehicle(id ?? 0),
    queryFn: async () => (await api.get<Vehicle>(`/vehicles/${id}`)).data,
    enabled: Boolean(id),
  });
}

/**
 * Fuel level history.
 *
 * The API paginates newest-first, which is right for a list and wrong for a
 * chart, so the series is reversed once here rather than in every consumer.
 * `meta.current` rides along so a caller does not need a second request to
 * label the latest point.
 */
export function useFuelReadings(id: number | undefined, filters: Record<string, unknown> = {}) {
  return useQuery({
    queryKey: queryKeys.fuelReadings(id ?? 0, filters),
    queryFn: async (): Promise<FuelReadingHistory> => {
      const envelope = await api.get<FuelReading[]>(
        `/vehicles/${id}/fuel-readings`,
        filters as never,
      );

      const meta = envelope.meta as
        | { current?: FuelReadingHistory['current']; tank_capacity?: number | null }
        | undefined;

      return {
        readings: [...(envelope.data ?? [])].reverse(),
        current: meta?.current ?? {
          fuel_pct: null,
          fuel_litres: null,
          recorded_at: null,
          status: null,
        },
        tank_capacity: meta?.tank_capacity ?? null,
      };
    },
    enabled: Boolean(id),
  });
}

export function useVehicleEfficiency(id: number | undefined) {
  return useQuery({
    queryKey: queryKeys.vehicleEfficiency(id ?? 0),
    queryFn: async () => (await api.get<VehicleEfficiency>(`/vehicles/${id}/efficiency`)).data,
    enabled: Boolean(id),
  });
}

export function useCreateVehicle() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<Vehicle>('/vehicles', payload)).data,
    onSuccess: () => {
      // The dashboard shows a vehicles section and an empty state that depends
      // on whether any exist, so both caches have to be dropped.
      queryClient.invalidateQueries({ queryKey: ['vehicles'] });
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard });
    },
  });
}

export function useRecordFuelReading(vehicleId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: { fuel_pct: number; recorded_at?: string }) =>
      (await api.post<FuelReadingResult>(`/vehicles/${vehicleId}/fuel-readings`, payload)).data,
    onSuccess: () => {
      // A reading changes the vehicle's cached level, so both the detail page
      // and every list showing a fuel column are now stale. The broad
      // ['vehicles'] key covers the list regardless of its filter combination.
      queryClient.invalidateQueries({ queryKey: ['vehicles'] });
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard });
    },
  });
}

// -------------------------------------------------------------- expenses ---

export function useExpenses(filters: Record<string, unknown> = {}) {
  return useQuery({
    queryKey: queryKeys.expenses(filters),
    queryFn: async () => (await api.get<FuelPurchase[]>('/expenses', filters as never)).data,
  });
}

export function useExpenseSummary(filters: Record<string, unknown> = {}) {
  return useQuery({
    queryKey: queryKeys.expenseSummary(filters),
    queryFn: async () =>
      (
        await api.get<{
          summary: ExpenseSummary;
          monthly_series: MonthlyPoint[];
          savings: SavingsAnalysis;
        }>('/expenses/summary', filters as never)
      ).data,
  });
}

/**
 * Read a fill-up off a photographed receipt.
 *
 * Nothing is invalidated on success because nothing was written — the scan
 * returns a draft, and the ordinary useLogFillUp mutation is still what creates
 * the purchase once the user has checked the figures.
 */
export function useScanReceipt() {
  return useMutation({
    mutationFn: async ({ file, vehicleId }: { file: File; vehicleId?: number }) => {
      const form = new FormData();
      form.append('image', file);
      if (vehicleId) form.append('vehicle_id', String(vehicleId));

      return (await api.upload<ReceiptScanResult>('/expenses/scan-receipt', form)).data;
    },
  });
}

export function useLogFillUp() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<FuelPurchase>('/expenses', payload)).data,
    onSuccess: () => {
      // A fill-up changes spend, efficiency and the dashboard tiles at once.
      queryClient.invalidateQueries({ queryKey: ['expenses'] });
      queryClient.invalidateQueries({ queryKey: ['vehicles'] });
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard });
    },
  });
}

// --------------------------------------------------------- notifications ---

export function useNotifications(unread = false) {
  return useQuery({
    queryKey: queryKeys.notifications(unread),
    queryFn: async () =>
      (await api.get<AppNotification[]>('/notifications', unread ? { unread: true } : {})).data,
  });
}

export function useMarkNotificationRead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: string) => (await api.patch(`/notifications/${id}/read`)).data,
    // Both the read and unread lists change, and so does the header count.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  });
}

export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => (await api.post('/notifications/read-all')).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  });
}

// --------------------------------------------------------------- profile ---

export function useUpdateProfile() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.put<User>('/profile', payload)).data,
    // The header shows the name and initials, and both come from this query.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['auth', 'me'] }),
  });
}

export function useUpdatePreferences() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.put<User>('/profile/preferences', payload)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['auth', 'me'] }),
  });
}

export function useChangePassword() {
  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.put('/profile/password', payload)).data,
  });
}

// ------------------------------------------------------------- assistant ---

export function useAskAssistant() {
  return useMutation({
    mutationFn: async (payload: {
      question: string;
      session_id?: number;
      latitude?: number;
      longitude?: number;
    }) => (await api.post<AssistantReply>('/assistant/chat', payload)).data,
  });
}

export function useRefuelRecommendation(vehicleId?: number, tankLevelPct?: number) {
  return useQuery({
    queryKey: ['assistant', 'should-i-refuel', vehicleId, tankLevelPct],
    queryFn: async () =>
      (
        await api.get<RefuelRecommendation>('/assistant/should-i-refuel', {
          vehicle_id: vehicleId,
          tank_level_pct: tankLevelPct,
        })
      ).data,
    staleTime: 30 * 60 * 1000,
  });
}

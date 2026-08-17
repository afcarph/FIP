'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api-client';
import type {
  Advisory,
  AppNotification,
  AssistantReply,
  CheapestStation,
  DashboardData,
  DeviceHealth,
  DeviceHealthFilter,
  DeviceHealthSummary,
  AdminUser,
  Company,
  FleetDriver,
  ExecutiveDashboard,
  ExpenseSummary,
  FleetAlert,
  FleetDashboard,
  FleetOverview,
  Forecast,
  FuelPurchase,
  FuelReading,
  FuelReadingHistory,
  FuelReadingResult,
  FuelType,
  HeatMapCell,
  MaintenanceDue,
  MaintenanceType,
  MonthlyPoint,
  PriceComparison,
  ReceiptScanResult,
  RefuelRecommendation,
  RegionalMovement,
  ReportDefinition,
  ReportRun,
  SavingsAnalysis,
  Station,
  SubscriptionTiers,
  TrendPoint,
  Trip,
  TripSummary,
  User,
  Vehicle,
  VehicleEfficiency,
  DeviceLocationPoint,
  OnboardingProgress,
  PlanCatalogue,
  SubscriptionCapacity,
  LocationHistoryLimits,
  VehicleLocation,
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
  fleetAlerts: (filters?: Record<string, unknown>) => ['fleet', 'alerts', filters] as const,
  fleetDevices: (filter?: string) => ['fleet', 'devices', filter ?? 'all'] as const,
  fleetLocations: () => ['fleet', 'locations'] as const,
  fleetSubscription: () => ['fleet', 'subscription'] as const,
  fleetOnboarding: () => ['fleet', 'onboarding'] as const,
  plans: () => ['plans'] as const,
  vehicleLocationHistory: (id: number, from: string, to: string) =>
    ['fleet', 'vehicles', id, 'locations', from, to] as const,
  companies: (filters?: Record<string, unknown>) => ['companies', filters] as const,
  company: (id: number) => ['companies', id] as const,
  fleetDrivers: (filters?: Record<string, unknown>) => ['fleet', 'drivers', filters] as const,
  adminUsers: (filters?: Record<string, unknown>) => ['admin', 'users', filters] as const,
  subscriptionTiers: () => ['admin', 'subscription-tiers'] as const,
  maintenanceDue: (days: number) => ['maintenance', 'due', days] as const,
  maintenanceTypes: () => ['maintenance', 'types'] as const,
  trips: (filters?: Record<string, unknown>) => ['trips', filters] as const,
  trip: (id: number) => ['trips', id] as const,
  tripSummary: () => ['trips', 'summary'] as const,
  reportDefinitions: () => ['reports', 'definitions'] as const,
  reportRuns: () => ['reports', 'runs'] as const,
  reportRun: (id: number) => ['reports', 'runs', id] as const,
  fleetDevice: (id: number) => ['fleet', 'devices', id] as const,
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
      (await api.get<FleetDashboard & { overview?: FleetOverview }>('/fleet/dashboard', { fleet_id: fleetId }))
        .data,
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

// ---------------------------------------------------------------- alerts ---

export function useFleetAlerts(filters: Record<string, unknown> = {}) {
  return useQuery({
    queryKey: queryKeys.fleetAlerts(filters),
    queryFn: async () => (await api.get<FleetAlert[]>('/fleet/fraud-alerts', filters as never)).data,
  });
}

export function useResolveAlert() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      id,
      status,
      note,
    }: {
      id: number;
      status: 'investigating' | 'confirmed' | 'dismissed';
      note?: string;
    }) =>
      (await api.patch(`/fleet/fraud-alerts/${id}`, {
        status,
        ...(note ? { resolution_note: note } : {}),
      })).data,
    onSuccess: () => {
      // The list is filtered by status, so resolving moves a row between
      // filters; the fleet dashboard also counts open alerts.
      queryClient.invalidateQueries({ queryKey: ['fleet', 'alerts'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
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

/**
 * Device health for the fleet, with the filter counts that go on the chips.
 *
 * The summary is returned alongside the page rather than derived from it: the
 * counts describe the whole fleet, and a filtered page of 25 cannot say how
 * many devices are offline.
 *
 * Refetched on an interval because this is a liveness view — a page that says
 * "online" for ten minutes after a device went quiet is worse than no page.
 */
export function useFleetDevices(filter?: DeviceHealthFilter) {
  return useQuery({
    queryKey: queryKeys.fleetDevices(filter),
    queryFn: async () => {
      const response = await api.get<DeviceHealth[]>(
        '/fleet/devices',
        filter ? { filter } : undefined,
      );

      return {
        devices: response.data,
        summary: response.meta?.summary as DeviceHealthSummary | undefined,
        pagination: response.meta?.pagination,
      };
    },
    refetchInterval: 60_000,
  });
}

/**
 * Where each tracked vehicle was last reported.
 *
 * Refetched on the same minute as device health, and for the same reason: a
 * map that keeps showing a position after the vehicle has moved is worse than
 * one that admits it has nothing. Positions are the freshest thing this app
 * holds, so they are never served from a stale cache on mount.
 */
export function useFleetLocations() {
  return useQuery({
    queryKey: queryKeys.fleetLocations(),
    queryFn: async () => (await api.get<VehicleLocation[]>('/fleet/locations')).data,
    refetchInterval: 60_000,
    staleTime: 0,
  });
}

/**
 * How far this company has got with first setup.
 *
 * Refetched when the window regains focus: a manager who leaves to add their
 * first vehicle in another tab should come back to a list that knows.
 */
export function useOnboarding() {
  return useQuery({
    queryKey: queryKeys.fleetOnboarding(),
    queryFn: async () => (await api.get<OnboardingProgress>('/fleet/onboarding')).data,
    refetchOnWindowFocus: true,
  });
}

/**
 * The plans on offer at registration.
 *
 * Public, and read before anybody has an account — so it must not carry the
 * authenticated client's assumptions. The limits it returns are rendered as
 * given rather than mirrored here.
 */
export function usePlans() {
  return useQuery({
    queryKey: queryKeys.plans(),
    queryFn: async () => (await api.get<PlanCatalogue>('/plans', undefined, { skipAuth: true })).data,
    staleTime: 5 * 60_000,
  });
}

/**
 * What the caller's own company is using against its plan.
 *
 * Scoped to the caller by the server, which takes the company from the token.
 * There is no company parameter to pass and none should be added.
 */
export function useFleetSubscription() {
  return useQuery({
    queryKey: queryKeys.fleetSubscription(),
    queryFn: async () => (await api.get<SubscriptionCapacity>('/fleet/subscription')).data,
  });
}

/**
 * Where one vehicle has been, over a window the caller chooses.
 *
 * Unlike current positions this is not polled. A track of the past does not
 * change while it is being read, and re-fetching it every minute would restart
 * a replay under the operator running it. It is also the one read in this app
 * that reconstructs a person's movements, so it happens when asked for and not
 * on a timer.
 *
 * The server's own limits come back in `meta.limits` rather than being
 * mirrored here — see the history endpoint.
 */
export function useVehicleLocationHistory(
  vehicleId: number | null,
  from: string,
  to: string,
) {
  return useQuery({
    queryKey: queryKeys.vehicleLocationHistory(vehicleId ?? 0, from, to),
    enabled: vehicleId !== null,
    queryFn: async () => {
      const response = await api.get<DeviceLocationPoint[]>(
        `/fleet/vehicles/${vehicleId}/locations`,
        // The server caps this at its own maximum; asking for more than it
        // allows is not an error, it just does not get more.
        { from, to, per_page: 2000 },
      );

      return {
        points: response.data,
        limits: response.meta?.limits as LocationHistoryLimits | undefined,
        pagination: response.meta?.pagination,
      };
    },
  });
}

export function useFleetDevice(id: number) {
  return useQuery({
    queryKey: queryKeys.fleetDevice(id),
    queryFn: async () => (await api.get<DeviceHealth>(`/fleet/devices/${id}`)).data,
    enabled: Number.isFinite(id) && id > 0,
    refetchInterval: 60_000,
  });
}

// ---------------------------------------------------------------- companies ---

/**
 * `enabled` because this endpoint is platform-only. A tenant user calling it
 * gets a 403, so a screen that needs the list for one role must not fetch it
 * for the others.
 */
export function useCompanies(filters: Record<string, unknown> = {}, enabled = true) {
  return useQuery({
    queryKey: queryKeys.companies(filters),
    queryFn: async () => (await api.get<Company[]>('/admin/companies', filters as never)).data,
    enabled,
  });
}

export function useCompany(id: number) {
  return useQuery({
    queryKey: queryKeys.company(id),
    queryFn: async () => (await api.get<Company>(`/admin/companies/${id}`)).data,
    enabled: Number.isFinite(id) && id > 0,
  });
}

export function useCreateCompany() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<Company>('/admin/companies', payload)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['companies'] }),
  });
}

export function useUpdateCompany() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...payload }: { id: number } & Record<string, unknown>) =>
      (await api.patch<Company>(`/admin/companies/${id}`, payload)).data,
    // Both caches: the tier shown on the detail page also appears in the list.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['companies'] }),
  });
}

// ------------------------------------------------------------------ drivers ---

export function useFleetDrivers(filters: Record<string, unknown> = {}) {
  return useQuery({
    queryKey: queryKeys.fleetDrivers(filters),
    queryFn: async () => (await api.get<FleetDriver[]>('/fleet/drivers', filters as never)).data,
  });
}

export function useCreateDriver() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<FleetDriver>('/fleet/drivers', payload)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['fleet', 'drivers'] }),
  });
}

export function useUpdateDriver() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...payload }: { id: number } & Record<string, unknown>) =>
      (await api.patch<FleetDriver>(`/fleet/drivers/${id}`, payload)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['fleet', 'drivers'] }),
  });
}

// -------------------------------------------------------------------- users ---

/**
 * The user listing, tenant-scoped by the API rather than here. A company
 * manager receives only their own company's people; a platform administrator
 * receives everyone. The client does no filtering of its own.
 */
export function useAdminUsers(filters: Record<string, unknown> = {}) {
  return useQuery({
    queryKey: queryKeys.adminUsers(filters),
    queryFn: async () => (await api.get<AdminUser[]>('/admin/users', filters as never)).data,
  });
}

export function useCreateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<AdminUser>('/admin/users', payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] });
      // A new user consumes a seat, so the company's usage figures move.
      queryClient.invalidateQueries({ queryKey: ['companies'] });
    },
  });
}

/**
 * The tier list, from the server rather than the client.
 *
 * Both company screens used to hardcode free/business/enterprise, so a tier
 * added in config never appeared and a renamed one was offered until the API
 * refused it. Rarely changes, so it is cached for the session.
 */
export function useSubscriptionTiers() {
  return useQuery({
    queryKey: queryKeys.subscriptionTiers(),
    queryFn: async () => (await api.get<SubscriptionTiers>('/admin/subscription-tiers')).data,
    staleTime: Infinity,
  });
}

// -------------------------------------------------------------- maintenance ---

/**
 * Services falling due, tenant-scoped by the API.
 *
 * The window includes anything already overdue: the scope is due_at on or
 * before now+days and not completed, so an item three weeks late still appears
 * under a 30-day view rather than dropping out of sight.
 */
export function useMaintenanceDue(days = 30) {
  return useQuery({
    queryKey: queryKeys.maintenanceDue(days),
    queryFn: async () => (await api.get<MaintenanceDue[]>('/maintenance/due', { days })).data,
  });
}

export function useMaintenanceTypes() {
  return useQuery({
    queryKey: queryKeys.maintenanceTypes(),
    queryFn: async () => (await api.get<MaintenanceType[]>('/maintenance/types')).data,
    staleTime: Infinity,
  });
}

/**
 * Trips, newest first.
 *
 * Tenant scoping is the API's job — `forUser` on the query — so the filters
 * here are only ever about what the operator wants to look at.
 */
export function useTrips(filters: Record<string, unknown> = {}) {
  return useQuery({
    queryKey: queryKeys.trips(filters),
    queryFn: async () => (await api.get<Trip[]>('/fleet/trips', filters as never)).data,
  });
}

export function useTripSummary() {
  return useQuery({
    queryKey: queryKeys.tripSummary(),
    queryFn: async () => (await api.get<TripSummary>('/fleet/trips/summary')).data,
  });
}

export function useTrip(id: number) {
  return useQuery({
    queryKey: queryKeys.trip(id),
    queryFn: async () => (await api.get<Trip>(`/fleet/trips/${id}`)).data,
    enabled: Number.isFinite(id) && id > 0,
  });
}

/**
 * Every lifecycle action invalidates the same three things.
 *
 * A transition changes the trip, the status counts, and how many vehicles the
 * fleet dashboard considers available — starting one takes a vehicle off the
 * road. Patching any of those by hand would eventually disagree with the
 * server about a state machine the server owns.
 */
function useTripAction<TBody>(path: (id: number) => string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...body }: { id: number } & TBody) =>
      (await api.post<Trip>(path(id), body)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['trips'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'fleet'] });
    },
  });
}

export function useUpdateTrip() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...payload }: { id: number } & Record<string, unknown>) =>
      (await api.patch<Trip>(`/fleet/trips/${id}`, payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['trips'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'fleet'] });
    },
  });
}

export function useCreateTrip() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<Trip>('/fleet/trips', payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['trips'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'fleet'] });
    },
  });
}

export const useDispatchTrip = () => useTripAction<object>((id) => `/fleet/trips/${id}/dispatch`);
export const useStartTrip = () => useTripAction<{ odometer_start?: number }>((id) => `/fleet/trips/${id}/start`);
export const useCompleteTrip = () =>
  useTripAction<{ odometer_end?: number; notes?: string }>((id) => `/fleet/trips/${id}/complete`);
export const useCancelTrip = () => useTripAction<{ reason: string }>((id) => `/fleet/trips/${id}/cancel`);

/**
 * Put a driver in a vehicle.
 *
 * The API releases whatever either of them was previously bound to, in one
 * transaction — a vehicle and a driver each hold at most one live assignment.
 * That means a single assign can change up to three rows the UI is showing,
 * so both rosters are invalidated rather than patched.
 */
export function useAssignDriver() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: { vehicle_id: number; driver_id: number; notes?: string }) =>
      (await api.post('/fleet/assignments', payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vehicles'] });
      queryClient.invalidateQueries({ queryKey: ['fleet'] });
    },
  });
}

/**
 * Take the current driver out of a vehicle.
 *
 * Keyed by vehicle rather than by assignment because that is the question the
 * screen is asking — and because the assignment id is not in the vehicle
 * payload. The record is dated, not deleted: fuel and fraud reporting read who
 * drove what between which dates.
 */
export function useReleaseDriver() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (vehicleId: number) =>
      (await api.delete(`/fleet/vehicles/${vehicleId}/assignment`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vehicles'] });
      queryClient.invalidateQueries({ queryKey: ['fleet'] });
    },
  });
}

/**
 * Reports the signed-in user may run.
 *
 * Permission-filtered by the API, and it changes only when roles do, so it is
 * cached for the session.
 */
export function useReportDefinitions() {
  return useQuery({
    queryKey: queryKeys.reportDefinitions(),
    queryFn: async () => (await api.get<ReportDefinition[]>('/reports/definitions')).data,
    staleTime: Infinity,
  });
}

export function useReportRuns() {
  return useQuery({
    queryKey: queryKeys.reportRuns(),
    queryFn: async () => (await api.get<ReportRun[]>('/reports/runs')).data,
  });
}

/**
 * Poll a run until it settles.
 *
 * Only enabled for a run that is actually pending: a report under the sync row
 * ceiling is rendered inline and comes back complete, so most generations never
 * poll at all. Stopping on `completed` and `failed` matters — a failed run
 * would otherwise be refetched every few seconds forever.
 */
export function useReportRun(id: number | null) {
  return useQuery({
    queryKey: queryKeys.reportRun(id ?? 0),
    queryFn: async () => (await api.get<ReportRun>(`/reports/runs/${id}`)).data,
    enabled: id !== null,
    refetchInterval: (query) => {
      const status = query.state.data?.status;

      return status === 'completed' || status === 'failed' ? false : 3000;
    },
  });
}

export function useGenerateReport() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<ReportRun>('/reports/generate', payload)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.reportRuns() }),
  });
}

/**
 * Save a generated report to disk.
 *
 * The file is streamed by the API behind the bearer token, so it is fetched
 * as a blob and handed to a synthetic link. The object URL is revoked straight
 * after; without that, every download would pin its own bytes in memory for
 * the life of the tab.
 */
export async function downloadReportRun(run: ReportRun): Promise<void> {
  const { blob, filename } = await api.download(`/reports/runs/${run.id}/download`);
  const href = URL.createObjectURL(blob);

  const link = document.createElement('a');
  link.href = href;
  link.download = filename ?? `${run.code ?? 'report'}.${run.format}`;
  document.body.appendChild(link);
  link.click();
  link.remove();

  URL.revokeObjectURL(href);
}

export function useRecordMaintenance() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ vehicleId, ...payload }: { vehicleId: number } & Record<string, unknown>) =>
      (await api.post(`/vehicles/${vehicleId}/maintenance`, payload)).data,
    onSuccess: () => {
      // Recording a service reschedules the next one, and the fleet dashboard
      // counts overdue and due-soon, so both caches are stale afterwards.
      queryClient.invalidateQueries({ queryKey: ['maintenance'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'fleet'] });
    },
  });
}

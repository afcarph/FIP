'use client';

import { Car, Fuel, Gauge, Plus, Search, TriangleAlert, WifiOff } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { FuelLevelBar, FuelStatusBadge } from '@/components/fleet/fuel-level';
import { StatCard } from '@/components/dashboard/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useVehicles } from '@/hooks/use-api';
import { formatDistance, formatEfficiency } from '@/lib/utils';
import type { Vehicle } from '@/types/api';

/** Days before expiry at which a document is worth flagging. */
const EXPIRY_WARNING_DAYS = 60;

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'in_maintenance', label: 'In maintenance' },
  { value: 'inactive', label: 'Inactive' },
] as const;

const SORTS = [
  { value: '', label: 'Default' },
  { value: 'plate_number', label: 'Plate' },
  { value: '-current_odometer', label: 'Odometer' },
  { value: '-avg_km_per_litre', label: 'Efficiency' },
] as const;

export default function VehiclesPage() {
  const [search, setSearch] = React.useState('');
  const [status, setStatus] = React.useState('');
  const [sort, setSort] = React.useState('');

  // Typing should not fire a request per keystroke; the API is paginated and
  // the list is the most-hit fleet endpoint.
  const debouncedSearch = useDebounced(search, 300);

  const filters = React.useMemo(
    () => ({
      ...(debouncedSearch ? { search: debouncedSearch } : {}),
      ...(status ? { status } : {}),
      ...(sort ? { sort } : {}),
      per_page: 100,
    }),
    [debouncedSearch, status, sort],
  );

  const { data: vehicles, isLoading, isFetching, isError } = useVehicles(filters);

  const isFiltered = Boolean(debouncedSearch || status || sort);
  const rows = vehicles ?? [];

  return (
    <div className="space-y-6">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Vehicles</h1>
          <p className="text-sm text-muted-foreground">
            Fuel level, efficiency and compliance across everything you run
          </p>
        </div>

        <Button asChild size="sm">
          <Link href="/vehicles/new">
            <Plus aria-hidden="true" />
            Add a vehicle
          </Link>
        </Button>
      </header>

      <FleetSummary vehicles={rows} loading={isLoading} />

      <Card>
        <CardContent className="space-y-4 pt-6">
          <Toolbar
            search={search}
            onSearch={setSearch}
            status={status}
            onStatus={setStatus}
            sort={sort}
            onSort={setSort}
            busy={isFetching && !isLoading}
          />

          {isLoading ? (
            <div className="space-y-2">
              {[0, 1, 2, 3].map((key) => (
                <Skeleton key={key} className="h-14 w-full" />
              ))}
            </div>
          ) : isError ? (
            // Distinct from an empty fleet on purpose. A failed request that
            // renders "No vehicles yet" tells an operator their fleet is gone.
            <EmptyState
              icon={WifiOff}
              title="Could not load vehicles"
              description="The list could not be fetched. This is a connection or server problem, not an empty fleet — reload to try again."
            />
          ) : rows.length === 0 ? (
            <EmptyState
              icon={Car}
              title={isFiltered ? 'No vehicles match those filters' : 'No vehicles yet'}
              description={
                isFiltered
                  ? 'Try a different plate, status or sort.'
                  : 'Add one to start tracking fuel, efficiency and running costs.'
              }
              action={
                isFiltered ? (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      setSearch('');
                      setStatus('');
                      setSort('');
                    }}
                  >
                    Clear filters
                  </Button>
                ) : (
                  <Button asChild size="sm">
                    <Link href="/vehicles/new">
                      <Plus aria-hidden="true" />
                      Add a vehicle
                    </Link>
                  </Button>
                )
              }
            />
          ) : (
            <VehicleTable vehicles={rows} />
          )}
        </CardContent>
      </Card>
    </div>
  );
}

// ------------------------------------------------------------------ parts ---

function FleetSummary({ vehicles, loading }: { vehicles: Vehicle[]; loading: boolean }) {
  // Derived from the rows already on screen rather than a second request: the
  // numbers must agree with the table beneath them, and a separate endpoint
  // scoped differently is exactly how those two drift apart.
  const withReading = vehicles.filter((v) => v.fuel.current_percentage !== null);
  const needsFuel = vehicles.filter(
    (v) => v.fuel.status === 'LOW' || v.fuel.status === 'CRITICAL',
  ).length;
  const critical = vehicles.filter((v) => v.fuel.status === 'CRITICAL').length;

  const avgEfficiency = (() => {
    const values = vehicles
      .map((v) => v.efficiency.avg_km_per_litre)
      .filter((value): value is number => value != null);

    return values.length ? values.reduce((a, b) => a + b, 0) / values.length : null;
  })();

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <StatCard
        label="Vehicles"
        value={loading ? '—' : String(vehicles.length)}
        icon={Car}
        hint={`${vehicles.filter((v) => v.status === 'active').length} active`}
        loading={loading}
      />
      <StatCard
        label="Needs fuel"
        value={loading ? '—' : String(needsFuel)}
        icon={Fuel}
        hint={critical ? `${critical} critical` : 'Low or critical'}
        accent={critical ? 'danger' : needsFuel ? 'warning' : 'success'}
        loading={loading}
      />
      <StatCard
        label="Reporting level"
        value={loading ? '—' : `${withReading.length}/${vehicles.length}`}
        icon={Gauge}
        hint="Vehicles with a fuel reading"
        accent={withReading.length === 0 ? 'warning' : 'primary'}
        loading={loading}
      />
      <StatCard
        label="Avg efficiency"
        value={formatEfficiency(avgEfficiency)}
        icon={Gauge}
        hint="Across vehicles with history"
        loading={loading}
      />
    </div>
  );
}

function Toolbar({
  search,
  onSearch,
  status,
  onStatus,
  sort,
  onSort,
  busy,
}: {
  search: string;
  onSearch: (value: string) => void;
  status: string;
  onStatus: (value: string) => void;
  sort: string;
  onSort: (value: string) => void;
  busy: boolean;
}) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
      <div className="relative flex-1">
        <Search
          className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          value={search}
          onChange={(event) => onSearch(event.target.value)}
          placeholder="Search plate, nickname or VIN"
          aria-label="Search vehicles"
          className="pl-9"
        />
      </div>

      <div className="flex items-center gap-2">
        <label className="sr-only" htmlFor="status-filter">
          Status
        </label>
        <select
          id="status-filter"
          value={status}
          onChange={(event) => onStatus(event.target.value)}
          className="h-9 rounded-lg border border-input bg-background px-3 text-sm"
        >
          {STATUS_FILTERS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        <label className="sr-only" htmlFor="sort-filter">
          Sort
        </label>
        <select
          id="sort-filter"
          value={sort}
          onChange={(event) => onSort(event.target.value)}
          className="h-9 rounded-lg border border-input bg-background px-3 text-sm"
        >
          {SORTS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        {/* Refetching on a filter change keeps the old rows visible, so the
            only cue that anything is happening has to be explicit. */}
        <span
          className="w-16 text-xs text-muted-foreground"
          role="status"
          aria-live="polite"
        >
          {busy ? 'Updating…' : ''}
        </span>
      </div>
    </div>
  );
}

function VehicleTable({ vehicles }: { vehicles: Vehicle[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[880px] text-sm">
        <thead>
          <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
            <th scope="col" className="py-2 pr-3 text-left font-medium">Vehicle</th>
            <th scope="col" className="px-3 py-2 text-left font-medium">Driver</th>
            <th scope="col" className="px-3 py-2 text-left font-medium">Fuel level</th>
            <th scope="col" className="px-3 py-2 text-left font-medium">Fuel status</th>
            <th scope="col" className="px-3 py-2 text-left font-medium">Status</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Odometer</th>
            <th scope="col" className="py-2 pl-3 text-right font-medium">km/L</th>
          </tr>
        </thead>

        <tbody>
          {vehicles.map((vehicle) => (
            <tr key={vehicle.id} className="border-b border-border/50 last:border-0">
              <td className="py-3 pr-3">
                <Link
                  href={`/vehicles/${vehicle.id}`}
                  className="font-medium hover:text-primary hover:underline"
                >
                  {vehicle.display_name}
                </Link>
                <p className="text-xs text-muted-foreground">
                  {vehicle.plate_number}
                  {vehicle.fuel_type ? ` · ${vehicle.fuel_type.name}` : ''}
                </p>
                <ExpiryFlags vehicle={vehicle} />
              </td>

              <td className="px-3 py-3">
                {vehicle.assigned_driver ? (
                  vehicle.assigned_driver.name
                ) : (
                  <span className="text-muted-foreground">Unassigned</span>
                )}
              </td>

              <td className="px-3 py-3">
                <FuelLevelBar
                  percentage={vehicle.fuel.current_percentage}
                  litres={vehicle.fuel.current_litres}
                  status={vehicle.fuel.status}
                  recordedAt={vehicle.fuel.recorded_at}
                />
              </td>

              <td className="px-3 py-3">
                <FuelStatusBadge status={vehicle.fuel.status} isStale={vehicle.fuel.is_stale} />
              </td>

              <td className="px-3 py-3">
                <Badge variant={vehicle.status === 'active' ? 'outline' : 'secondary'}>
                  {vehicle.status.replace(/_/g, ' ')}
                </Badge>
              </td>

              <td className="tabular px-3 py-3 text-right">
                {formatDistance(vehicle.current_odometer)}
              </td>

              <td className="tabular py-3 pl-3 text-right">
                {formatEfficiency(vehicle.efficiency.avg_km_per_litre)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ExpiryFlags({ vehicle }: { vehicle: Vehicle }) {
  const flags = [
    { label: 'Registration', days: vehicle.documents.registration_expires_in_days },
    { label: 'Insurance', days: vehicle.documents.insurance_expires_in_days },
  ]
    // null means no date on file, which is not the same as expiring today.
    .filter((flag) => flag.days !== null && flag.days <= EXPIRY_WARNING_DAYS);

  if (flags.length === 0) return null;

  return (
    <div className="mt-1 flex flex-wrap gap-1">
      {flags.map((flag) => (
        <Badge key={flag.label} variant={flag.days! < 0 ? 'destructive' : 'secondary'}>
          <TriangleAlert className="size-3" aria-hidden="true" />
          {flag.days! < 0
            ? `${flag.label} expired`
            : `${flag.label} ${Math.round(flag.days!)}d`}
        </Badge>
      ))}
    </div>
  );
}

// ------------------------------------------------------------------ hooks ---

function useDebounced<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = React.useState(value);

  React.useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}

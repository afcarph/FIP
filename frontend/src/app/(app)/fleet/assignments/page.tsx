'use client';

import { ArrowLeft, Car, IdCard } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useAssignDriver, useFleetDrivers, useReleaseDriver, useVehicles } from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';
import type { FleetDriver, Vehicle } from '@/types/api';

/**
 * One vehicle and whoever is driving it.
 *
 * The select offers only drivers who are free. A driver already in another
 * vehicle can be reassigned by the API — it releases both sides in one
 * transaction — but offering that here would let someone quietly empty another
 * vehicle from a screen that shows no sign of it.
 */
function VehicleRow({
  vehicle,
  available,
  onError,
}: {
  vehicle: Vehicle;
  available: FleetDriver[];
  onError: (message: string | null) => void;
}) {
  const assign = useAssignDriver();
  const release = useReleaseDriver();
  const [chosen, setChosen] = React.useState('');

  const busy = assign.isPending || release.isPending;

  const run = async (action: Promise<unknown>) => {
    onError(null);

    try {
      await action;
    } catch (cause) {
      onError(cause instanceof ApiError ? cause.message : 'That change could not be saved.');
    }
  };

  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-border px-4 py-3 last:border-0">
      <Car className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium">{vehicle.plate_number}</span>
          {!vehicle.assigned_driver && <Badge variant="secondary">Unassigned</Badge>}
        </div>
        <p className="truncate text-sm text-muted-foreground">
          {[vehicle.make, vehicle.model].filter(Boolean).join(' ') || 'Vehicle'}
        </p>
      </div>

      {vehicle.assigned_driver ? (
        <>
          <div className="flex min-w-0 flex-1 items-center gap-2 text-sm">
            <IdCard className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
            <span className="truncate">{vehicle.assigned_driver.name}</span>
          </div>

          <Button
            variant="ghost"
            size="sm"
            disabled={busy}
            onClick={() => run(release.mutateAsync(vehicle.id))}
          >
            {release.isPending ? 'Releasing…' : 'Release'}
          </Button>
        </>
      ) : (
        <div className="flex flex-1 items-center gap-2">
          <select
            aria-label={`Assign a driver to ${vehicle.plate_number}`}
            value={chosen}
            onChange={(event) => setChosen(event.target.value)}
            disabled={busy || available.length === 0}
            className="h-9 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 text-sm"
          >
            <option value="">
              {available.length === 0 ? 'No unassigned drivers' : 'Choose a driver…'}
            </option>
            {available.map((driver) => (
              <option key={driver.id} value={driver.id}>
                {driver.full_name}
              </option>
            ))}
          </select>

          <Button
            size="sm"
            disabled={!chosen || busy}
            onClick={async () => {
              await run(
                assign.mutateAsync({ vehicle_id: vehicle.id, driver_id: Number(chosen) }),
              );

              // Cleared either way: on success the row re-renders as assigned,
              // and on failure a stale selection would misrepresent the state.
              setChosen('');
            }}
          >
            {assign.isPending ? 'Assigning…' : 'Assign'}
          </Button>
        </div>
      )}
    </div>
  );
}

function AssignmentsPage() {
  const { data: vehicles, isLoading: vehiclesLoading, isError } = useVehicles();
  const { data: drivers, isLoading: driversLoading } = useFleetDrivers();
  const [error, setError] = React.useState<string | null>(null);

  const list = vehicles ?? [];

  // Only drivers holding no live assignment. `assigned_vehicle` is the roster's
  // own view of the same relationship the vehicle list reads from the other
  // side, so the two agree.
  const available = (drivers ?? []).filter((driver) => !driver.assigned_vehicle);

  const assigned = list.filter((vehicle) => vehicle.assigned_driver).length;

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
        <h1 className="text-2xl font-semibold">Driver assignments</h1>
        <p className="text-sm text-muted-foreground">
          Who is driving what. {assigned} of {list.length} vehicles assigned
          {available.length > 0 ? `, ${available.length} drivers free` : ''}.
        </p>
      </div>

      {error ? <p className="text-sm text-destructive">{error}</p> : null}

      {(vehiclesLoading || driversLoading) && <Skeleton className="h-64 w-full" />}

      {isError && (
        <EmptyState
          icon={Car}
          title="Could not load vehicles"
          description="The fleet could not be fetched. Reload the page to try again."
        />
      )}

      {!vehiclesLoading && !isError && list.length === 0 && (
        <EmptyState
          icon={Car}
          title="No vehicles yet"
          description="Add a vehicle before assigning anyone to drive it."
        />
      )}

      {list.length > 0 && (
        <Card>
          <CardContent className="p-0">
            {list.map((vehicle) => (
              <VehicleRow
                key={vehicle.id}
                vehicle={vehicle}
                available={available}
                onError={setError}
              />
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

export default function Page() {
  return (
    <RequireRole roles={['fleet_manager', 'company_manager', 'super_admin', 'system_admin']}>
      <AssignmentsPage />
    </RequireRole>
  );
}

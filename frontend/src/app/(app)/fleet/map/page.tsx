'use client';

import { ArrowLeft, MapPinOff, Navigation, Satellite, WifiOff } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { FleetMap } from '@/components/map/fleet-map';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useFleetLocations } from '@/hooks/use-api';
import { googleMapsUrl, hasPlottableCoordinates } from '@/lib/map-config';
import { cn, formatRelative } from '@/lib/utils';
import type { VehicleLocation } from '@/types/api';

/**
 * The fleet map.
 *
 * Answers one question — where is each vehicle now — and refuses to imply more
 * than the data supports. Two things it is careful about:
 *
 * A position has an age. Every row says when it was reported, and anything the
 * server calls stale is drawn and labelled as stale rather than shown as a
 * confident dot, because dispatching against a day-old fix sends someone to
 * the wrong place.
 *
 * A vehicle absent from the map is not a vehicle that is nowhere. It is one
 * whose device has never reported, which is a device problem and belongs on
 * the device health page — so the count is stated here and linked there
 * instead of leaving an operator to notice the gap.
 */

function RowStatus({ vehicle }: { vehicle: VehicleLocation }) {
  if (!hasPlottableCoordinates(vehicle.latitude, vehicle.longitude)) {
    return <Badge variant="destructive">Position unusable</Badge>;
  }

  return vehicle.is_fresh ? (
    <Badge variant="secondary">Reporting</Badge>
  ) : (
    <Badge variant="outline">Stale</Badge>
  );
}

function VehicleRow({
  vehicle,
  selected,
  onSelect,
}: {
  vehicle: VehicleLocation;
  selected: boolean;
  onSelect: () => void;
}) {
  const plottable = hasPlottableCoordinates(vehicle.latitude, vehicle.longitude);

  return (
    <div
      className={cn(
        'border-b border-border px-4 py-3 last:border-0',
        selected && 'bg-muted/60',
      )}
    >
      <div className="flex items-start gap-3">
        <button
          type="button"
          onClick={onSelect}
          disabled={!plottable}
          className="min-w-0 flex-1 text-left disabled:cursor-default"
        >
          <div className="flex items-center gap-2">
            <span className="truncate font-medium">
              {vehicle.plate_number ?? `Vehicle ${vehicle.vehicle_id}`}
            </span>
            <RowStatus vehicle={vehicle} />
          </div>

          <p className="truncate text-xs text-muted-foreground">
            {vehicle.driver_name ?? 'No driver on the device'}
            {vehicle.display_name ? ` · ${vehicle.display_name}` : ''}
          </p>

          <p className="mt-0.5 text-xs text-muted-foreground">
            Reported {formatRelative(vehicle.recorded_at)}
          </p>
        </button>

        {plottable ? (
          <a
            href={googleMapsUrl.showLocation(vehicle.latitude as number, vehicle.longitude as number)}
            target="_blank"
            rel="noreferrer noopener"
            className="shrink-0 rounded-md border border-border p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
            aria-label={`Open ${vehicle.plate_number ?? 'this vehicle'}'s position in Google Maps`}
          >
            <Navigation className="h-4 w-4" aria-hidden />
          </a>
        ) : null}
      </div>
    </div>
  );
}

function FleetMapPage() {
  const { data, isLoading, isError } = useFleetLocations();
  const [selectedId, setSelectedId] = React.useState<number | null>(null);

  const vehicles = React.useMemo(() => data ?? [], [data]);

  // Freshest first: the vehicles an operator can still act on belong at the
  // top, and within each group the most recently heard from leads.
  const ordered = React.useMemo(
    () =>
      [...vehicles].sort((a, b) => {
        if (a.is_fresh !== b.is_fresh) return a.is_fresh ? -1 : 1;

        return (b.recorded_at ?? '').localeCompare(a.recorded_at ?? '');
      }),
    [vehicles],
  );

  const fresh = vehicles.filter((vehicle) => vehicle.is_fresh).length;
  const stale = vehicles.length - fresh;

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
        <h1 className="text-2xl font-semibold">Fleet map</h1>
        <p className="text-sm text-muted-foreground">
          The last position reported by each vehicle&apos;s device. Positions refresh on their own
          every minute.
        </p>
      </div>

      {isLoading && <Skeleton className="h-[520px] w-full" />}

      {isError && (
        <EmptyState
          icon={WifiOff}
          title="Could not load positions"
          description="The map could not be fetched. It refreshes on its own, or reload the page."
        />
      )}

      {!isLoading && !isError && vehicles.length === 0 && (
        <EmptyState
          icon={MapPinOff}
          title="No vehicle has reported a position"
          description="A vehicle appears here once the device attached to it reports where it is. Device health shows which handsets are online and whether they are allowed to report position."
        />
      )}

      {!isLoading && !isError && vehicles.length > 0 && (
        <>
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
            <span className="inline-flex items-center gap-1.5 text-foreground">
              <Satellite className="h-4 w-4" aria-hidden />
              {fresh} reporting now
            </span>
            {stale > 0 && <span>{stale} showing a stale position</span>}
            <Link href="/fleet/devices" className="underline underline-offset-2 hover:text-foreground">
              Vehicles missing from this map are on device health
            </Link>
          </div>

          <div className="grid gap-4 lg:grid-cols-5">
            <div className="lg:col-span-3">
              <FleetMap
                vehicles={vehicles}
                selectedId={selectedId}
                onSelect={(vehicle) => setSelectedId(vehicle.vehicle_id)}
                className="h-[520px]"
              />
            </div>

            <div className="lg:col-span-2">
              <Card>
                <CardContent className="p-0">
                  {ordered.map((vehicle) => (
                    <VehicleRow
                      key={vehicle.vehicle_id}
                      vehicle={vehicle}
                      selected={vehicle.vehicle_id === selectedId}
                      onSelect={() => setSelectedId(vehicle.vehicle_id)}
                    />
                  ))}
                </CardContent>
              </Card>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

export default function Page() {
  return (
    <RequireRole roles={['fleet_manager', 'company_manager', 'super_admin', 'system_admin']}>
      <FleetMapPage />
    </RequireRole>
  );
}

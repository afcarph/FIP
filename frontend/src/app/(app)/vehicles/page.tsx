'use client';

import { Car, Fuel, Plus } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useVehicles } from '@/hooks/use-api';
import { formatDistance, formatEfficiency } from '@/lib/utils';

/** Days before expiry at which a document is worth flagging. */
const EXPIRY_WARNING_DAYS = 60;

function ExpiryBadge({ label, days }: { label: string; days: number | null }) {
  // null means no date on file, which is not the same as expiring today — the
  // API used to conflate the two and every new vehicle looked overdue.
  if (days === null || days > EXPIRY_WARNING_DAYS) {
    return null;
  }

  const overdue = days < 0;

  return (
    <Badge variant={overdue ? 'destructive' : 'secondary'}>
      {overdue
        ? `${label} expired ${Math.abs(Math.round(days))}d ago`
        : `${label} due in ${Math.round(days)}d`}
    </Badge>
  );
}

export default function VehiclesPage() {
  const { data: vehicles, isLoading } = useVehicles();

  return (
    <div className="space-y-6">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Vehicles</h1>
          <p className="text-sm text-muted-foreground">
            Efficiency, odometer and documents for everything you run
          </p>
        </div>

        <Button asChild size="sm">
          <Link href="/vehicles/new">
            <Plus aria-hidden="true" />
            Add a vehicle
          </Link>
        </Button>
      </header>

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2">
          {[0, 1].map((key) => (
            <Skeleton key={key} className="h-40 w-full" />
          ))}
        </div>
      ) : !vehicles?.length ? (
        <EmptyState
          icon={Car}
          title="No vehicles yet"
          description="Add one to start tracking efficiency and running costs."
          action={
            <Button asChild size="sm">
              <Link href="/vehicles/new">
                <Plus aria-hidden="true" />
                Add a vehicle
              </Link>
            </Button>
          }
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {vehicles.map((vehicle) => (
            <Card key={vehicle.id}>
              <CardContent className="space-y-4 pt-6">
                <div className="flex items-start gap-3">
                  <span className="rounded-xl bg-muted p-2 text-muted-foreground">
                    <Car className="size-5" aria-hidden="true" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{vehicle.display_name}</p>
                    <p className="truncate text-sm text-muted-foreground">
                      {vehicle.plate_number}
                      {vehicle.fuel_type ? ` · ${vehicle.fuel_type.name}` : ''}
                    </p>
                  </div>
                </div>

                <dl className="grid grid-cols-3 gap-2 text-sm">
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-muted-foreground">
                      Odometer
                    </dt>
                    <dd className="font-medium">{formatDistance(vehicle.current_odometer)}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-muted-foreground">
                      Efficiency
                    </dt>
                    <dd className="font-medium">
                      {formatEfficiency(vehicle.efficiency.avg_km_per_litre)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-muted-foreground">Range</dt>
                    <dd className="font-medium">
                      {formatDistance(vehicle.efficiency.estimated_range_km)}
                    </dd>
                  </div>
                </dl>

                <div className="flex flex-wrap gap-2 empty:hidden">
                  <ExpiryBadge
                    label="Registration"
                    days={vehicle.documents.registration_expires_in_days}
                  />
                  <ExpiryBadge
                    label="Insurance"
                    days={vehicle.documents.insurance_expires_in_days}
                  />
                  {vehicle.assigned_driver ? (
                    <Badge variant="outline">
                      <Fuel aria-hidden="true" />
                      {vehicle.assigned_driver.name}
                    </Badge>
                  ) : null}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

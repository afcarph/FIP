'use client';

import { ArrowLeft, UserX } from 'lucide-react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { DriverSetup } from '@/components/fleet/driver-setup';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useDriverDevices, useFleetDrivers } from '@/hooks/use-api';

/**
 * Setting one driver up on the app.
 *
 * Reached from the onboarding checklist, which points here for the driver who
 * is already assigned to a vehicle — the person that step is actually about.
 *
 * Whether their phone has reported is read from the driver's own devices, not
 * from fleet device health. Health lists handsets that are already attached to
 * a vehicle, so it answered "not installed" for every driver who had installed
 * the app but had no assignment yet — which is precisely the state this page
 * exists to move people out of. Nothing about setup progress is stored
 * anywhere; both this and the checklist derive it from the devices table.
 */
function DriverSetupPage() {
  const params = useParams<{ id: string }>();
  const driverId = Number(params.id);

  const { data: drivers, isLoading } = useFleetDrivers();
  const { data: devices } = useDriverDevices(driverId);

  const driver = drivers?.find((candidate) => candidate.id === driverId);

  /*
   * The endpoint returns active handsets only — ios/android, not revoked, the
   * same definition the checklist uses — so a row existing is the whole
   * answer. A revoked phone comes back as no rows and correctly reads as "no
   * active device", and a vehicle is not consulted either way.
   *
   * An attached one is preferred over merely the most recent, because a driver
   * who replaced their phone can hold two: showing the newer, unattached one
   * would say "not reporting" while the older handset reports perfectly well.
   */
  const device = devices?.find((candidate) => candidate.vehicle) ?? devices?.[0] ?? null;

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (!driver) {
    return (
      <EmptyState
        icon={UserX}
        title="No such driver"
        description="This driver is not on your roster. They may have been removed, or belong to another company."
      />
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/fleet/drivers"
          className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Drivers
        </Link>
        <h1 className="text-2xl font-semibold">Set up {driver.full_name}</h1>
        <p className="text-sm text-muted-foreground">
          What it takes to get this driver reporting from their phone, and where they have got to.
        </p>
      </div>

      <DriverSetup driver={driver} device={device} />
    </div>
  );
}

export default function Page() {
  return (
    // The same roles that manage drivers. Creating the login itself is gated
    // again on the server by `drivers.invite`, which is narrower than this.
    <RequireRole roles={['fleet_manager', 'company_manager', 'super_admin', 'system_admin']}>
      <DriverSetupPage />
    </RequireRole>
  );
}

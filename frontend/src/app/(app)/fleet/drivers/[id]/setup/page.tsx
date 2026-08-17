'use client';

import { ArrowLeft, UserX } from 'lucide-react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { DriverSetup } from '@/components/fleet/driver-setup';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useFleetDevices, useFleetDrivers } from '@/hooks/use-api';

/**
 * Setting one driver up on the app.
 *
 * Reached from the onboarding checklist, which points here for the driver who
 * is already assigned to a vehicle — the person that step is actually about.
 *
 * Whether their phone has reported is read from device health rather than
 * tracked here, so this page and the checklist answer the question the same
 * way: a registered, unrevoked handset belonging to this driver. Nothing about
 * setup progress is stored anywhere.
 */
function DriverSetupPage() {
  const params = useParams<{ id: string }>();
  const driverId = Number(params.id);

  const { data: drivers, isLoading } = useFleetDrivers();
  const { data: health } = useFleetDevices();

  const driver = drivers?.find((candidate) => candidate.id === driverId);

  // A handset belonging to this driver's account. Device health already
  // excludes revoked devices, so a revoked phone correctly reads as "nothing
  // has reported yet" — the same answer the onboarding step gives.
  const hasDevice = Boolean(
    driver?.account &&
      (health?.devices ?? []).some(
        (device) =>
          // `driver` on a device health row is the *user* who owns it, which is
          // what a driver's account id is. Revoked and browser rows are excluded
          // for the same reasons the onboarding step excludes them.
          device.driver?.id === driver.account?.id &&
          device.platform !== 'web' &&
          !device.is_revoked,
      ),
  );

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

      <DriverSetup driver={driver} hasDevice={hasDevice} />
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

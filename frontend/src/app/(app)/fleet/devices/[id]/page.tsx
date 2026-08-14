'use client';

import { ArrowLeft, WifiOff } from 'lucide-react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { BatteryReading } from '@/components/fleet/battery-reading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useFleetDevice } from '@/hooks/use-api';
import { formatDateTime, formatRelative } from '@/lib/utils';
import type { DeviceHealth } from '@/types/api';

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-border py-2.5 last:border-0">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span className="text-right text-sm">{children}</span>
    </div>
  );
}

function DeviceDetail({ device }: { device: DeviceHealth }) {
  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/fleet/devices"
          className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Device health
        </Link>

        <div className="flex flex-wrap items-center gap-2">
          <h1 className="text-2xl font-semibold">
            {device.vehicle?.plate_number ?? 'Unassigned device'}
          </h1>
          {device.is_revoked && <Badge variant="destructive">Revoked</Badge>}
          {!device.is_online && !device.is_revoked && <Badge variant="destructive">Not reporting</Badge>}
        </div>

        <p className="text-sm text-muted-foreground">
          {device.device_name ?? 'Unnamed device'} · {device.platform}
        </p>
      </div>

      <Card>
        <CardContent className="p-4">
          <h2 className="mb-2 font-medium">Status</h2>

          <Field label="Battery">
            <BatteryReading battery={device.battery} showTimestamp />
          </Field>

          <Field label="Charging">
            {device.battery.state === null
              ? '—'
              : device.battery.is_charging
                ? 'Yes'
                : 'No'}
          </Field>

          {/*
            Online and tracking are separate rows because they are separate
            facts. A revoked handset still talks to the API, so it can be
            online and not tracking — collapsing them into one status would
            tell an operator the opposite of what is true.
          */}
          <Field label="Reporting">
            {device.is_online ? 'Online' : 'Offline'}
            {device.last_seen_at && (
              <span className="block text-xs text-muted-foreground">
                Last seen {formatRelative(device.last_seen_at)}
              </span>
            )}
          </Field>

          <Field label="Tracking">
            {device.is_tracking
              ? 'Reporting position'
              : device.is_revoked
                ? 'Revoked — cannot report'
                : 'No vehicle attached'}
          </Field>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-4">
          <h2 className="mb-2 font-medium">Assignment</h2>

          <Field label="Driver">
            {device.driver ? (
              <>
                {device.driver.name}
                <span className="block text-xs text-muted-foreground">{device.driver.email}</span>
              </>
            ) : (
              '—'
            )}
          </Field>

          <Field label="Vehicle">
            {device.vehicle ? (
              <Link href={`/vehicles/${device.vehicle.id}`} className="underline">
                {device.vehicle.plate_number}
              </Link>
            ) : (
              'None'
            )}
          </Field>

          <Field label="Last location">
            {device.last_location ? (
              <>
                {device.last_location.latitude.toFixed(5)}, {device.last_location.longitude.toFixed(5)}
                <span className="block text-xs text-muted-foreground">
                  {formatRelative(device.last_location.recorded_at)}
                </span>
              </>
            ) : (
              'Never reported'
            )}
          </Field>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-4">
          <h2 className="mb-2 font-medium">Build</h2>

          <Field label="App version">{device.app_version ?? 'Not reported'}</Field>
          <Field label="OS version">{device.os_version ?? 'Not reported'}</Field>
          <Field label="Registered">
            {device.registered_at ? formatDateTime(device.registered_at) : '—'}
          </Field>
          {device.revoked_at && (
            <Field label="Revoked">{formatDateTime(device.revoked_at)}</Field>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function DeviceDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params?.id);
  const { data, isLoading, isError } = useFleetDevice(id);

  if (isLoading) return <Skeleton className="h-96 w-full" />;

  if (isError || !data) {
    return (
      <EmptyState
        icon={WifiOff}
        title="Device not available"
        description="This device could not be loaded. It may belong to another company, or it may no longer exist."
      />
    );
  }

  return <DeviceDetail device={data} />;
}

export default function Page() {
  return (
    <RequireRole roles={['fleet_manager', 'company_manager', 'super_admin', 'system_admin']}>
      <DeviceDetailPage />
    </RequireRole>
  );
}

'use client';

import {
  ArrowLeft,
  BatteryLow,
  BatteryWarning,
  Plug,
  Smartphone,
  WifiOff,
} from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { BatteryReading } from '@/components/fleet/battery-reading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useFleetDevices } from '@/hooks/use-api';
import { formatRelative } from '@/lib/utils';
import type { DeviceHealth, DeviceHealthFilter } from '@/types/api';

const FILTERS: { value: DeviceHealthFilter | ''; label: string; countKey: string }[] = [
  { value: '', label: 'All devices', countKey: 'total' },
  { value: 'online', label: 'Online', countKey: 'online' },
  { value: 'offline', label: 'Offline', countKey: 'offline' },
  { value: 'low_battery', label: 'Low battery', countKey: 'low_battery' },
  { value: 'charging', label: 'Charging', countKey: 'charging' },
];

/**
 * The one-line reason this device needs attention, or null if it does not.
 *
 * Ordered by what would cost a fleet the most. A device nobody has heard from
 * is worse than a low battery, because a flat battery at least explains itself
 * — and a revoked device outranks both: it is not coming back on its own.
 */
function concern(device: DeviceHealth): { label: string; tone: 'danger' | 'warning' } | null {
  if (device.is_revoked) return { label: 'Revoked', tone: 'danger' };
  if (!device.is_online) return { label: 'Not reporting', tone: 'danger' };
  if (device.battery.is_low) return { label: 'Low battery', tone: 'warning' };
  if (!device.is_tracking) return { label: 'No vehicle', tone: 'warning' };

  return null;
}

function DeviceRow({ device }: { device: DeviceHealth }) {
  const issue = concern(device);

  return (
    <Link
      href={`/fleet/devices/${device.id}`}
      className="flex items-center gap-4 border-b border-border px-4 py-3 last:border-0 hover:bg-muted/50"
    >
      <Smartphone className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium">
            {device.vehicle?.plate_number ?? 'Unassigned device'}
          </span>
          {issue && (
            <Badge variant={issue.tone === 'danger' ? 'destructive' : 'secondary'}>
              {issue.label}
            </Badge>
          )}
        </div>
        <p className="truncate text-sm text-muted-foreground">
          {device.driver?.name ?? 'Unknown driver'}
          {device.device_name ? ` · ${device.device_name}` : ''}
        </p>
      </div>

      <div className="hidden w-28 shrink-0 sm:block">
        <BatteryReading battery={device.battery} />
      </div>

      <div className="w-32 shrink-0 text-right">
        <p className="text-sm">{device.is_online ? 'Online' : 'Offline'}</p>
        <p className="text-xs text-muted-foreground">
          {device.last_seen_at ? formatRelative(device.last_seen_at) : 'Never reported'}
        </p>
      </div>
    </Link>
  );
}

function DeviceHealthPage() {
  const [filter, setFilter] = React.useState<DeviceHealthFilter | ''>('');
  const { data, isLoading, isError } = useFleetDevices(filter || undefined);

  const summary = data?.summary;
  const devices = data?.devices ?? [];

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
        <h1 className="text-2xl font-semibold">Device health</h1>
        <p className="text-sm text-muted-foreground">
          A vehicle is only on the map while the phone reporting for it is awake and charged.
          These are the devices carrying your vehicles.
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        {FILTERS.map((option) => {
          const count = summary?.[option.countKey as keyof typeof summary];

          return (
            <button
              key={option.value || 'all'}
              type="button"
              onClick={() => setFilter(option.value)}
              aria-pressed={filter === option.value}
              className={
                filter === option.value
                  ? 'rounded-full bg-primary px-3 py-1.5 text-sm text-primary-foreground'
                  : 'rounded-full border border-border px-3 py-1.5 text-sm hover:bg-muted'
              }
            >
              {option.label}
              {count !== undefined && (
                <span className="ml-1.5 opacity-70">{count}</span>
              )}
            </button>
          );
        })}
      </div>

      {isLoading && <Skeleton className="h-64 w-full" />}

      {isError && (
        <EmptyState
          icon={WifiOff}
          title="Could not load device health"
          description="The list could not be fetched. It refreshes on its own, or reload the page."
        />
      )}

      {!isLoading && !isError && devices.length === 0 && (
        <EmptyState
          icon={filter === 'low_battery' ? BatteryLow : filter === 'charging' ? Plug : BatteryWarning}
          title={filter ? 'Nothing matches this filter' : 'No devices are reporting yet'}
          description={
            filter
              ? 'Try another filter — the counts above show where your devices are.'
              : 'A device appears here once a driver registers it and attaches it to one of your vehicles.'
          }
        />
      )}

      {devices.length > 0 && (
        <Card>
          <CardContent className="p-0">
            {devices.map((device) => (
              <DeviceRow key={device.id} device={device} />
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
      <DeviceHealthPage />
    </RequireRole>
  );
}

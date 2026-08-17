'use client';

import { ArrowLeft, MapPinOff, Pause, Play, RotateCcw, WifiOff } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { RequirePermission } from '@/components/auth/require-permission';
import { HistoryMap, plottablePoints } from '@/components/map/history-map';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useVehicleLocationHistory, useVehicles } from '@/hooks/use-api';
import { googleMapsUrl } from '@/lib/map-config';
import { cn } from '@/lib/utils';
import type { DeviceLocationPoint } from '@/types/api';

/**
 * Where a vehicle has been, and a replay of how it got there.
 *
 * This is the one screen in the product that reconstructs a person's
 * movements, and it is built to keep saying so. It asks for one vehicle and
 * one window rather than offering the fleet at once; it never polls, because a
 * record of the past does not change while it is read; and it draws only what
 * was recorded, with the holes left visibly empty.
 *
 * It is guarded by `devices.location.history`, which a company manager
 * deliberately does not hold — seeing the fleet now and replaying a driver's
 * week are different questions, and the second one has to be granted.
 */

/** Windows an operator actually asks for, as whole local days. */
const PRESETS = [
  { key: 'today', label: 'Today', days: 0 },
  { key: 'yesterday', label: 'Yesterday', days: 1 },
  { key: 'week', label: 'Last 7 days', days: 7 },
] as const;

type PresetKey = (typeof PRESETS)[number]['key'];

function windowFor(preset: PresetKey): { from: string; to: string } {
  const now = new Date();
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());

  if (preset === 'yesterday') {
    const start = new Date(startOfToday);
    start.setDate(start.getDate() - 1);

    return { from: start.toISOString(), to: startOfToday.toISOString() };
  }

  if (preset === 'week') {
    const start = new Date(startOfToday);
    start.setDate(start.getDate() - 6);

    return { from: start.toISOString(), to: now.toISOString() };
  }

  return { from: startOfToday.toISOString(), to: now.toISOString() };
}

function timeOf(point: DeviceLocationPoint | null | undefined): string {
  if (!point?.recorded_at) return '—';

  return new Date(point.recorded_at).toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/**
 * Replay steps from fix to fix rather than in real time.
 *
 * Real time would be unwatchable: fixes are minutes apart, and most of a
 * working day is a vehicle sitting still. Stepping through what was actually
 * recorded shows the same journey in the time an operator will give it, and
 * the timestamp on screen keeps the real clock visible.
 */
const STEP_MS = 600;

function Replay({ points }: { points: DeviceLocationPoint[] }) {
  const [index, setIndex] = React.useState(0);
  const [playing, setPlaying] = React.useState(false);

  // A new track invalidates the position in the old one.
  const signature = points.map((point) => point.id).join(',');

  React.useEffect(() => {
    setIndex(0);
    setPlaying(false);
  }, [signature]);

  React.useEffect(() => {
    if (!playing) return;

    const timer = window.setInterval(() => {
      setIndex((current) => {
        if (current >= points.length - 1) {
          setPlaying(false);

          return current;
        }

        return current + 1;
      });
    }, STEP_MS);

    return () => window.clearInterval(timer);
  }, [playing, points.length]);

  const cursor = points[index] ?? null;
  const atEnd = index >= points.length - 1;

  return (
    <div className="space-y-4">
      <HistoryMap points={points} cursor={cursor} className="h-[520px]" />

      <Card>
        <CardContent className="space-y-3 p-4">
          <div className="flex flex-wrap items-center gap-3">
            <Button
              type="button"
              size="sm"
              variant={playing ? 'secondary' : 'default'}
              onClick={() => {
                // Replaying from the end would look broken. Starting over is
                // what "play" means once a track has finished.
                if (atEnd && !playing) setIndex(0);
                setPlaying(!playing);
              }}
              disabled={points.length < 2}
            >
              {playing ? <Pause aria-hidden /> : <Play aria-hidden />}
              {playing ? 'Pause' : 'Play'}
            </Button>

            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => {
                setPlaying(false);
                setIndex(0);
              }}
              disabled={index === 0}
            >
              <RotateCcw aria-hidden />
              Back to start
            </Button>

            <span className="text-sm text-muted-foreground">
              Fix {points.length === 0 ? 0 : index + 1} of {points.length}
            </span>
          </div>

          <input
            type="range"
            min={0}
            max={Math.max(points.length - 1, 0)}
            value={index}
            onChange={(event) => {
              setPlaying(false);
              setIndex(Number(event.target.value));
            }}
            className="w-full"
            aria-label="Position in the track"
            disabled={points.length < 2}
          />

          <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
            <div>
              <dt className="text-xs text-muted-foreground">Recorded</dt>
              <dd className="font-medium">{timeOf(cursor)}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Speed</dt>
              <dd className="font-medium">
                {cursor?.speed_kph == null ? 'Not reported' : `${Math.round(cursor.speed_kph)} km/h`}
              </dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Accuracy</dt>
              <dd className="font-medium">
                {cursor?.accuracy_m == null ? 'Not reported' : `±${Math.round(cursor.accuracy_m)} m`}
              </dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">This fix</dt>
              <dd>
                {cursor ? (
                  <a
                    className="font-medium underline underline-offset-2"
                    href={googleMapsUrl.showLocation(cursor.latitude, cursor.longitude)}
                    target="_blank"
                    rel="noreferrer noopener"
                  >
                    Open in Google Maps
                  </a>
                ) : (
                  '—'
                )}
              </dd>
            </div>
          </dl>
        </CardContent>
      </Card>
    </div>
  );
}

function LocationHistoryPage() {
  const [vehicleId, setVehicleId] = React.useState<number | null>(null);
  const [preset, setPreset] = React.useState<PresetKey>('today');

  const { data: vehicles, isLoading: loadingVehicles } = useVehicles();

  // Recomputed only when the preset changes: a window derived on every render
  // would move its own end forward continuously and refetch the track under
  // the operator.
  const window_ = React.useMemo(() => windowFor(preset), [preset]);

  const { data, isLoading, isError, error } = useVehicleLocationHistory(
    vehicleId,
    window_.from,
    window_.to,
  );

  const points = React.useMemo(() => plottablePoints(data?.points ?? []), [data]);
  const truncated =
    data?.pagination !== undefined && data.pagination.total > (data.points?.length ?? 0);

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
        <h1 className="text-2xl font-semibold">Location history</h1>
        <p className="text-sm text-muted-foreground">
          The positions one vehicle recorded over a chosen window, and a replay of them. Only what
          was actually reported is drawn — gaps are left as gaps.
        </p>
      </div>

      <Card>
        <CardContent className="flex flex-wrap items-end gap-4 p-4">
          <div className="min-w-56">
            <label htmlFor="vehicle" className="mb-1 block text-xs text-muted-foreground">
              Vehicle
            </label>
            <select
              id="vehicle"
              className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              value={vehicleId ?? ''}
              onChange={(event) =>
                setVehicleId(event.target.value === '' ? null : Number(event.target.value))
              }
              disabled={loadingVehicles}
            >
              <option value="">Choose a vehicle</option>
              {(vehicles ?? []).map((vehicle) => (
                <option key={vehicle.id} value={vehicle.id}>
                  {vehicle.plate_number}
                  {vehicle.nickname ? ` · ${vehicle.nickname}` : ''}
                </option>
              ))}
            </select>
          </div>

          <div>
            <span className="mb-1 block text-xs text-muted-foreground">Window</span>
            <div className="flex flex-wrap gap-2">
              {PRESETS.map((option) => (
                <Button
                  key={option.key}
                  type="button"
                  size="sm"
                  variant={preset === option.key ? 'default' : 'outline'}
                  onClick={() => setPreset(option.key)}
                >
                  {option.label}
                </Button>
              ))}
            </div>
          </div>
        </CardContent>
      </Card>

      {vehicleId === null && (
        <EmptyState
          icon={MapPinOff}
          title="Choose a vehicle"
          description="Pick a vehicle and a window to see where it went. Nothing is loaded until you ask for it."
        />
      )}

      {vehicleId !== null && isLoading && <Skeleton className="h-[520px] w-full" />}

      {vehicleId !== null && isError && (
        <EmptyState
          icon={WifiOff}
          title="Could not load the track"
          description={
            error instanceof Error && error.message
              ? error.message
              : 'The history could not be fetched. Try a shorter window, or reload the page.'
          }
        />
      )}

      {vehicleId !== null && !isLoading && !isError && points.length === 0 && (
        <EmptyState
          icon={MapPinOff}
          title="Nothing was recorded in this window"
          description="This vehicle's device reported no position between those times. It may have been switched off, offline, or not reporting location at all — device health says which."
        />
      )}

      {vehicleId !== null && !isLoading && !isError && points.length > 0 && (
        <>
          <div
            className={cn(
              'flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground',
              truncated && 'text-amber-700 dark:text-amber-500',
            )}
          >
            <span>
              {points.length} position{points.length === 1 ? '' : 's'} recorded
            </span>
            <span>
              {timeOf(points[0])} → {timeOf(points[points.length - 1])}
            </span>
            {truncated && (
              <span>
                Showing the first {points.length} of {data?.pagination?.total} — narrow the window to
                see the rest.
              </span>
            )}
          </div>

          <Replay points={points} />
        </>
      )}
    </div>
  );
}

export default function Page() {
  return (
    <RequirePermission
      permission="devices.location.history"
      reason="Replaying where a vehicle has been is limited to accounts granted location history. Seeing where the fleet is now is on the fleet map."
    >
      <LocationHistoryPage />
    </RequirePermission>
  );
}

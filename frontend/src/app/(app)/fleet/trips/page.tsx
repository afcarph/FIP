'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft, Route } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { FormError } from '@/components/auth/form-error';
import { RequireRole } from '@/components/auth/require-role';
import { TripStatusBadge } from '@/components/fleet/trip-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
  useCancelTrip,
  useCompleteTrip,
  useCreateTrip,
  useDispatchTrip,
  useFleetDrivers,
  useStartTrip,
  useTripSummary,
  useTrips,
  useVehicles,
} from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';
import { formatDate } from '@/lib/utils';
import type { Trip, TripStatus } from '@/types/api';

const FILTERS: Array<{ value: TripStatus | 'all'; label: string }> = [
  { value: 'all', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'dispatched', label: 'Dispatched' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
];

const schema = z.object({
  vehicle_id: z.string().min(1, 'Choose a vehicle.'),
  driver_id: z.string().min(1, 'Choose a driver.'),
  origin_label: z.string().min(1, 'Where does it start?').max(180),
  destination_label: z.string().min(1, 'Where is it going?').max(180),
  purpose: z.string().max(180).optional(),
  scheduled_for: z.string().optional(),
  notes: z.string().max(2000).optional(),
});

type FormValues = z.infer<typeof schema>;

/**
 * Plan a trip.
 *
 * The vehicle and driver lists exclude anything already committed to an
 * unfinished trip. The API refuses those pairings anyway — this is not the
 * guard — but offering one would be offering an action that cannot succeed.
 */
function TripForm({ busy, onDone }: { busy: { vehicles: Set<number>; drivers: Set<number> }; onDone: () => void }) {
  const creation = useCreateTrip();
  const { data: vehicles } = useVehicles();
  const { data: drivers } = useFleetDrivers();

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const freeVehicles = (vehicles ?? []).filter((v) => v.status === 'active' && !busy.vehicles.has(v.id));
  const freeDrivers = (drivers ?? []).filter((d) => d.status === 'active' && !busy.drivers.has(d.id));
  const error = creation.error instanceof ApiError ? creation.error : null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Plan a trip</CardTitle>
        <p className="text-sm text-muted-foreground">
          Creating a trip does not send it out. It is saved as a draft until you dispatch it.
        </p>
      </CardHeader>
      <CardContent>
        <form
          className="space-y-5"
          onSubmit={handleSubmit(async (values) => {
            await creation.mutateAsync({
              ...values,
              vehicle_id: Number(values.vehicle_id),
              driver_id: Number(values.driver_id),
              purpose: values.purpose || undefined,
              scheduled_for: values.scheduled_for || undefined,
              notes: values.notes || undefined,
            });

            reset();
            onDone();
          })}
        >
          {error ? <FormError>{error.message}</FormError> : null}

          <div className="grid gap-5 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="vehicle_id">Vehicle</Label>
              <select
                id="vehicle_id"
                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                {...register('vehicle_id')}
              >
                <option value="">
                  {freeVehicles.length === 0 ? 'No vehicles free' : 'Choose a vehicle…'}
                </option>
                {freeVehicles.map((vehicle) => (
                  <option key={vehicle.id} value={vehicle.id}>
                    {vehicle.plate_number}
                  </option>
                ))}
              </select>
              {errors.vehicle_id ? (
                <p className="text-sm text-destructive">{errors.vehicle_id.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="driver_id">Driver</Label>
              <select
                id="driver_id"
                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                {...register('driver_id')}
              >
                <option value="">
                  {freeDrivers.length === 0 ? 'No drivers free' : 'Choose a driver…'}
                </option>
                {freeDrivers.map((driver) => (
                  <option key={driver.id} value={driver.id}>
                    {driver.full_name}
                  </option>
                ))}
              </select>
              {errors.driver_id ? (
                <p className="text-sm text-destructive">{errors.driver_id.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="origin_label">From</Label>
              <Input id="origin_label" placeholder="Manila" {...register('origin_label')} />
              {errors.origin_label ? (
                <p className="text-sm text-destructive">{errors.origin_label.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="destination_label">To</Label>
              <Input id="destination_label" placeholder="Batangas" {...register('destination_label')} />
              {errors.destination_label ? (
                <p className="text-sm text-destructive">{errors.destination_label.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="purpose">Purpose</Label>
              <Input id="purpose" placeholder="Delivery" {...register('purpose')} />
            </div>

            <div className="space-y-2">
              <Label htmlFor="scheduled_for">Scheduled for</Label>
              <Input id="scheduled_for" type="datetime-local" {...register('scheduled_for')} />
            </div>

            <div className="space-y-2 sm:col-span-2">
              <Label htmlFor="notes">Notes</Label>
              <Input id="notes" {...register('notes')} />
            </div>
          </div>

          <div className="flex justify-end gap-3">
            <Button variant="ghost" type="button" onClick={onDone}>
              Cancel
            </Button>
            <Button type="submit" disabled={creation.isPending}>
              {creation.isPending ? 'Saving…' : 'Save as draft'}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

/**
 * One trip, with only the actions its current state allows.
 *
 * `trip.can` comes from the server's own transition table, so this cannot
 * offer a move the API would refuse — the button set and the state machine
 * have a single definition between them.
 */
function TripRow({ trip, onError }: { trip: Trip; onError: (message: string | null) => void }) {
  const dispatchTrip = useDispatchTrip();
  const start = useStartTrip();
  const complete = useCompleteTrip();
  const cancel = useCancelTrip();

  /*
   * Closing and cancelling ask for something, so they open an inline panel
   * rather than a native prompt. window.prompt is blocked outright in
   * sandboxed contexts — the click did nothing at all and the trip silently
   * stayed open — and it cannot validate, label a field, or be styled.
   */
  const [asking, setAsking] = React.useState<'start' | 'complete' | 'cancel' | null>(null);
  const [odometer, setOdometer] = React.useState('');
  const [reason, setReason] = React.useState('');

  const busy =
    dispatchTrip.isPending || start.isPending || complete.isPending || cancel.isPending;

  const close = () => {
    setAsking(null);
    setOdometer('');
    setReason('');
  };

  const run = async (action: Promise<unknown>) => {
    onError(null);

    try {
      await action;
    } catch (cause) {
      onError(cause instanceof ApiError ? cause.message : 'That change could not be saved.');
    }
  };

  const when =
    trip.timeline.ended_at ??
    trip.timeline.started_at ??
    trip.timeline.dispatched_at ??
    trip.timeline.scheduled_for ??
    trip.timeline.created_at;

  return (
    <div className="border-b border-border last:border-0">
    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <Link href={`/fleet/trips/${trip.id}`} className="truncate font-medium hover:underline">
            {trip.reference_no ?? `Trip ${trip.id}`}
          </Link>
          <TripStatusBadge status={trip.status} />
        </div>
        <p className="truncate text-sm text-muted-foreground">
          {trip.origin ?? '—'} → {trip.destination ?? '—'}
          {trip.purpose ? ` · ${trip.purpose}` : ''}
        </p>
      </div>

      <div className="hidden w-40 shrink-0 text-sm sm:block">
        <span className="truncate">{trip.vehicle?.plate_number ?? '—'}</span>
        <p className="truncate text-xs text-muted-foreground">{trip.driver?.name ?? 'No driver'}</p>
      </div>

      <div className="hidden w-28 shrink-0 text-sm text-muted-foreground md:block">
        {when ? formatDate(when) : '—'}
      </div>

      <div className="flex shrink-0 gap-1">
        {trip.can.includes('dispatched') && (
          <Button size="sm" variant="ghost" disabled={busy} onClick={() => run(dispatchTrip.mutateAsync({ id: trip.id }))}>
            Dispatch
          </Button>
        )}

        {trip.can.includes('in_progress') && (
          <Button size="sm" variant="ghost" disabled={busy} onClick={() => setAsking('start')}>
            Start
          </Button>
        )}

        {trip.can.includes('completed') && (
          <Button size="sm" variant="ghost" disabled={busy} onClick={() => setAsking('complete')}>
            Complete
          </Button>
        )}

        {trip.can.includes('cancelled') && (
          <Button size="sm" variant="ghost" disabled={busy} onClick={() => setAsking('cancel')}>
            Cancel
          </Button>
        )}

        <Button size="sm" variant="ghost" asChild>
          <Link href={`/fleet/trips/${trip.id}`}>View</Link>
        </Button>
      </div>
    </div>

      {asking === 'start' && (
        <div className="flex flex-wrap items-end gap-3 border-t border-border bg-muted/40 px-4 py-3">
          <div className="space-y-1">
            <Label htmlFor={`odo-start-${trip.id}`} className="text-xs">
              Opening odometer (km)
            </Label>
            <Input
              id={`odo-start-${trip.id}`}
              type="number"
              inputMode="numeric"
              value={odometer}
              onChange={(event) => setOdometer(event.target.value)}
              className="h-8 w-40"
            />
          </div>
          {/*
            Collected here because it is the only moment it is true. Without an
            opening reading the closing one has nothing to be measured against,
            and the trip can never report a distance.
          */}
          <p className="mb-1.5 text-xs text-muted-foreground">
            Optional, but distance is only calculated when both readings exist.
          </p>
          <div className="mb-0.5 ml-auto flex gap-2">
            <Button size="sm" variant="ghost" onClick={close}>
              Cancel
            </Button>
            <Button
              size="sm"
              disabled={busy}
              onClick={async () => {
                await run(
                  start.mutateAsync({
                    id: trip.id,
                    odometer_start: odometer.trim() === '' ? undefined : Number(odometer),
                  }),
                );
                close();
              }}
            >
              {start.isPending ? 'Starting…' : 'Start trip'}
            </Button>
          </div>
        </div>
      )}

      {asking === 'complete' && (
        <div className="flex flex-wrap items-end gap-3 border-t border-border bg-muted/40 px-4 py-3">
          <div className="space-y-1">
            <Label htmlFor={`odo-${trip.id}`} className="text-xs">
              Closing odometer (km)
            </Label>
            <Input
              id={`odo-${trip.id}`}
              type="number"
              inputMode="numeric"
              value={odometer}
              onChange={(event) => setOdometer(event.target.value)}
              className="h-8 w-40"
            />
          </div>
          {/*
            Optional, and said so. Not every operator records a reading, and
            refusing to close the trip without one would leave it open forever.
          */}
          <p className="mb-1.5 text-xs text-muted-foreground">
            Leave blank if it was not recorded.
          </p>
          <div className="mb-0.5 ml-auto flex gap-2">
            <Button size="sm" variant="ghost" onClick={close}>
              Cancel
            </Button>
            <Button
              size="sm"
              disabled={busy}
              onClick={async () => {
                await run(
                  complete.mutateAsync({
                    id: trip.id,
                    odometer_end: odometer.trim() === '' ? undefined : Number(odometer),
                  }),
                );
                close();
              }}
            >
              {complete.isPending ? 'Closing…' : 'Complete trip'}
            </Button>
          </div>
        </div>
      )}

      {asking === 'cancel' && (
        <div className="flex flex-wrap items-end gap-3 border-t border-border bg-muted/40 px-4 py-3">
          <div className="min-w-0 flex-1 space-y-1">
            <Label htmlFor={`why-${trip.id}`} className="text-xs">
              Why is it being cancelled?
            </Label>
            <Input
              id={`why-${trip.id}`}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="Customer postponed"
              className="h-8"
            />
          </div>
          <div className="mb-0.5 flex gap-2">
            <Button size="sm" variant="ghost" onClick={close}>
              Back
            </Button>
            {/* Required: a cancelled trip with no reason tells the next reader nothing. */}
            <Button
              size="sm"
              disabled={busy || reason.trim() === ''}
              onClick={async () => {
                await run(cancel.mutateAsync({ id: trip.id, reason: reason.trim() }));
                close();
              }}
            >
              {cancel.isPending ? 'Cancelling…' : 'Cancel trip'}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

function TripsPage() {
  const [status, setStatus] = React.useState<TripStatus | 'all'>('all');
  const [creating, setCreating] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const { data: trips, isLoading, isError } = useTrips(status === 'all' ? {} : { status });
  const { data: summary } = useTripSummary();

  // Everything currently committed, for the create form. Taken from the
  // unfiltered active list rather than whatever the operator is looking at,
  // so filtering to "completed" cannot make a busy vehicle look free.
  const { data: active } = useTrips({ status: undefined });
  const busy = React.useMemo(() => {
    const open = (active ?? []).filter((t) => t.status !== 'completed' && t.status !== 'cancelled');

    return {
      vehicles: new Set(open.map((t) => t.vehicle?.id).filter((id): id is number => id !== undefined)),
      drivers: new Set(open.map((t) => t.driver?.id).filter((id): id is number => id !== undefined)),
    };
  }, [active]);

  const list = trips ?? [];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link
            href="/fleet"
            className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden />
            Fleet
          </Link>
          <h1 className="text-2xl font-semibold">Trips &amp; dispatch</h1>
          <p className="text-sm text-muted-foreground">
            Plan a job, send it out, and close it when the vehicle is back.
          </p>
        </div>

        {!creating && <Button onClick={() => setCreating(true)}>Plan a trip</Button>}
      </div>

      {summary ? (
        <div className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-5">
          {(['draft', 'dispatched', 'in_progress', 'completed', 'cancelled'] as const).map((key) => (
            <button
              key={key}
              type="button"
              onClick={() => setStatus(key)}
              aria-pressed={status === key}
              className={`bg-card px-4 py-3 text-left transition-colors hover:bg-muted ${
                status === key ? 'ring-1 ring-inset ring-primary' : ''
              }`}
            >
              <span className="block text-xl font-semibold tabular-nums">{summary[key]}</span>
              <span className="block text-xs text-muted-foreground">
                {FILTERS.find((f) => f.value === key)?.label}
              </span>
            </button>
          ))}
        </div>
      ) : null}

      <div className="flex flex-wrap gap-2">
        {FILTERS.map((filter) => (
          <button
            key={filter.value}
            type="button"
            onClick={() => setStatus(filter.value)}
            aria-pressed={status === filter.value}
            className={
              status === filter.value
                ? 'rounded-full bg-primary px-3 py-1.5 text-sm text-primary-foreground'
                : 'rounded-full border border-border px-3 py-1.5 text-sm hover:bg-muted'
            }
          >
            {filter.label}
          </button>
        ))}
      </div>

      {creating && <TripForm busy={busy} onDone={() => setCreating(false)} />}

      {error ? <p className="text-sm text-destructive">{error}</p> : null}

      {isLoading && <Skeleton className="h-64 w-full" />}

      {isError && (
        <EmptyState
          icon={Route}
          title="Could not load trips"
          description="The trip list could not be fetched. Reload the page to try again."
        />
      )}

      {!isLoading && !isError && list.length === 0 && (
        <EmptyState
          icon={Route}
          title={status === 'all' ? 'No trips yet' : 'Nothing in this state'}
          description={
            status === 'all'
              ? 'Plan a trip and it will appear here as a draft.'
              : 'Try another status filter.'
          }
        />
      )}

      {list.length > 0 && (
        <Card>
          <CardContent className="p-0">
            {list.map((trip) => (
              <TripRow key={trip.id} trip={trip} onError={setError} />
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

export default function Page() {
  return (
    <RequireRole roles={['fleet_manager', 'company_manager', 'viewer', 'super_admin', 'system_admin']}>
      <TripsPage />
    </RequireRole>
  );
}

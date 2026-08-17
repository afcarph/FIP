'use client';

import { ArrowLeft, Route } from 'lucide-react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { TripStatusBadge } from '@/components/fleet/trip-status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useTrip } from '@/hooks/use-api';
import { formatDateTime, formatNumber } from '@/lib/utils';
import type { Trip } from '@/types/api';

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-border py-2.5 last:border-0">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span className="text-right text-sm">{children ?? '—'}</span>
    </div>
  );
}

/**
 * The timeline is the trip's own record of what happened.
 *
 * Only stamps that exist are shown. A missing one is not "pending" — a
 * cancelled trip has no start, and rendering an empty row for it would imply
 * the trip is still waiting to leave.
 */
function Timeline({ trip }: { trip: Trip }) {
  const steps: Array<[string, string | null]> = [
    ['Created', trip.timeline.created_at],
    ['Scheduled for', trip.timeline.scheduled_for],
    ['Dispatched', trip.timeline.dispatched_at],
    ['Started', trip.timeline.started_at],
    ['Completed', trip.timeline.ended_at],
    ['Cancelled', trip.timeline.cancelled_at],
  ];

  const present = steps.filter(([, at]) => at !== null);

  return (
    <CardContent>
      {present.map(([label, at]) => (
        <Field key={label} label={label}>
          {formatDateTime(at as string)}
        </Field>
      ))}
    </CardContent>
  );
}

function TripDetail({ trip }: { trip: Trip }) {
  const distance = trip.distance_km;

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/fleet/trips"
          className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Trips
        </Link>

        <div className="flex flex-wrap items-center gap-2">
          <h1 className="text-2xl font-semibold">{trip.reference_no ?? `Trip ${trip.id}`}</h1>
          <TripStatusBadge status={trip.status} />
        </div>
        <p className="text-sm text-muted-foreground">
          {trip.origin ?? '—'} → {trip.destination ?? '—'}
        </p>
      </div>

      {trip.status === 'cancelled' && trip.cancellation_reason ? (
        <div className="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3">
          <p className="text-sm font-medium">Cancelled</p>
          <p className="text-sm text-muted-foreground">{trip.cancellation_reason}</p>
        </div>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Assignment</CardTitle>
          </CardHeader>
          <CardContent>
            <Field label="Vehicle">{trip.vehicle?.plate_number}</Field>
            <Field label="Driver">{trip.driver?.name}</Field>
            <Field label="Purpose">{trip.purpose}</Field>
            <Field label="Planned by">{trip.created_by}</Field>
            <Field label="Notes">{trip.notes}</Field>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Timeline</CardTitle>
          </CardHeader>
          <Timeline trip={trip} />
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Odometer</CardTitle>
          </CardHeader>
          <CardContent>
            <Field label="Start">
              {trip.odometer.start === null ? null : `${formatNumber(trip.odometer.start)} km`}
            </Field>
            <Field label="End">
              {trip.odometer.end === null ? null : `${formatNumber(trip.odometer.end)} km`}
            </Field>
            {/*
              Distance is derived from the two readings and only when both are
              present, so a dash here means "not measured" rather than zero.
            */}
            <Field label="Distance">
              {distance === null ? null : `${formatNumber(distance)} km`}
            </Field>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

function TripDetailPage() {
  const params = useParams<{ id: string }>();
  const { data, isLoading, isError } = useTrip(Number(params?.id));

  if (isLoading) return <Skeleton className="h-96 w-full" />;

  if (isError || !data) {
    return (
      <EmptyState
        icon={Route}
        title="Trip not available"
        description="This trip could not be loaded. It may not exist, or it may belong to another company."
      />
    );
  }

  return <TripDetail trip={data} />;
}

export default function Page() {
  return (
    <RequireRole roles={['fleet_manager', 'company_manager', 'viewer', 'super_admin', 'system_admin']}>
      <TripDetailPage />
    </RequireRole>
  );
}

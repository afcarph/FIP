'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft, Wrench } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { FormError } from '@/components/auth/form-error';
import { RequireRole } from '@/components/auth/require-role';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useMaintenanceDue, useMaintenanceTypes, useRecordMaintenance } from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';
import { formatDate, formatNumber } from '@/lib/utils';
import type { MaintenanceDue } from '@/types/api';

/**
 * How far ahead to look.
 *
 * Every window includes what is already overdue — the API filters on due_at
 * being on or before now plus the window, so a service three weeks late still
 * appears under "next 30 days" rather than vanishing because its date passed.
 */
const WINDOWS = [
  { days: 30, label: 'Next 30 days' },
  { days: 60, label: 'Next 60 days' },
  { days: 90, label: 'Next 90 days' },
] as const;

const schema = z.object({
  maintenance_type_id: z.coerce.number().int().positive('Choose what was done.'),
  performed_at: z.string().min(1, 'When was it done?'),
  odometer: z.string().optional(),
  cost: z.string().optional(),
  vendor: z.string().max(180).optional(),
  notes: z.string().max(500).optional(),
});

type FormValues = z.infer<typeof schema>;

function RecordForm({ item, onDone }: { item: MaintenanceDue; onDone: () => void }) {
  const record = useRecordMaintenance();
  const { data: types } = useMaintenanceTypes();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    // Today, in the browser's own date, because the API refuses a future date.
    defaultValues: { performed_at: new Date().toISOString().slice(0, 10) },
  });

  const error = record.error instanceof ApiError ? record.error : null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          Record a service — {item.vehicle ?? 'vehicle'}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          Logging this reschedules the next one from the odometer and date you give.
        </p>
      </CardHeader>
      <CardContent>
        <form
          className="space-y-5"
          onSubmit={handleSubmit(async (values) => {
            await record.mutateAsync({
              vehicleId: item.vehicle_id,
              maintenance_type_id: values.maintenance_type_id,
              performed_at: values.performed_at,
              // Empty strings would fail the API's numeric rules, where these
              // fields are genuinely optional.
              odometer: values.odometer ? Number(values.odometer) : undefined,
              cost: values.cost ? Number(values.cost) : undefined,
              vendor: values.vendor || undefined,
              notes: values.notes || undefined,
            });

            onDone();
          })}
        >
          {error ? <FormError>{error.message}</FormError> : null}

          <div className="grid gap-5 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="maintenance_type_id">Service</Label>
              <select
                id="maintenance_type_id"
                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                {...register('maintenance_type_id')}
              >
                <option value="">Choose…</option>
                {(types ?? []).map((type) => (
                  <option key={type.id} value={type.id}>
                    {type.name}
                  </option>
                ))}
              </select>
              {errors.maintenance_type_id ? (
                <p className="text-sm text-destructive">{errors.maintenance_type_id.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="performed_at">Date</Label>
              <Input id="performed_at" type="date" {...register('performed_at')} />
              {errors.performed_at ? (
                <p className="text-sm text-destructive">{errors.performed_at.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="odometer">Odometer (km)</Label>
              <Input id="odometer" type="number" inputMode="numeric" {...register('odometer')} />
            </div>

            <div className="space-y-2">
              <Label htmlFor="cost">Cost</Label>
              <Input id="cost" type="number" step="0.01" inputMode="decimal" {...register('cost')} />
            </div>

            <div className="space-y-2 sm:col-span-2">
              <Label htmlFor="vendor">Workshop</Label>
              <Input id="vendor" {...register('vendor')} />
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
            <Button type="submit" disabled={record.isPending}>
              {record.isPending ? 'Saving…' : 'Record service'}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

/**
 * The three states a schedule can be in within the window.
 *
 * `scheduled` is the one worth naming separately: the window includes anything
 * dated on or before its end, so a service three months out appears here while
 * being neither overdue nor imminent. Badging it "due soon" would overstate it.
 */
const STATUS: Record<string, { label: string; variant: 'destructive' | 'warning' | 'secondary' }> = {
  overdue: { label: 'Overdue', variant: 'destructive' },
  due_soon: { label: 'Due soon', variant: 'warning' },
  scheduled: { label: 'Scheduled', variant: 'secondary' },
};

function DueRow({ item, onRecord }: { item: MaintenanceDue; onRecord: () => void }) {
  const overdue = item.status === 'overdue';
  const badge = STATUS[item.status] ?? { label: item.status, variant: 'secondary' as const };

  return (
    <div className="flex items-center gap-4 border-b border-border px-4 py-3 last:border-0">
      <Wrench
        className={`h-5 w-5 shrink-0 ${overdue ? 'text-destructive' : 'text-muted-foreground'}`}
        aria-hidden
      />

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium">{item.vehicle ?? 'Unknown vehicle'}</span>
          <Badge variant={badge.variant}>{badge.label}</Badge>
        </div>
        <p className="truncate text-sm text-muted-foreground">
          {item.service ?? 'Scheduled service'}
        </p>
      </div>

      <div className="hidden w-32 shrink-0 text-sm text-muted-foreground sm:block">
        {/*
          null is unknown, not zero. A schedule with no odometer target, or a
          vehicle with no reading, has no distance to report — and showing 0 km
          would read as "due right now".
        */}
        {item.km_remaining === null
          ? '—'
          : item.km_remaining < 0
            ? `${formatNumber(Math.abs(item.km_remaining))} km over`
            : `${formatNumber(item.km_remaining)} km left`}
      </div>

      <div className="w-28 shrink-0 text-right text-sm">
        <span className={overdue ? 'text-destructive' : ''}>
          {item.due_at ? formatDate(item.due_at) : 'No date'}
        </span>
      </div>

      <Button variant="ghost" size="sm" onClick={onRecord}>
        Record
      </Button>
    </div>
  );
}

function MaintenancePage() {
  const [days, setDays] = React.useState<number>(30);
  const [recording, setRecording] = React.useState<MaintenanceDue | null>(null);
  const { data, isLoading, isError } = useMaintenanceDue(days);

  const items = data ?? [];
  const overdue = items.filter((i) => i.status === 'overdue').length;

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
        <h1 className="text-2xl font-semibold">Maintenance</h1>
        <p className="text-sm text-muted-foreground">
          What needs booking in, soonest first.
          {overdue > 0 ? ` ${overdue} already overdue.` : ''}
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        {WINDOWS.map((window) => (
          <button
            key={window.days}
            type="button"
            onClick={() => setDays(window.days)}
            aria-pressed={days === window.days}
            className={
              days === window.days
                ? 'rounded-full bg-primary px-3 py-1.5 text-sm text-primary-foreground'
                : 'rounded-full border border-border px-3 py-1.5 text-sm hover:bg-muted'
            }
          >
            {window.label}
          </button>
        ))}
      </div>

      {recording && (
        <RecordForm item={recording} onDone={() => setRecording(null)} />
      )}

      {isLoading && <Skeleton className="h-64 w-full" />}

      {isError && (
        <EmptyState
          icon={Wrench}
          title="Could not load maintenance"
          description="The schedule could not be fetched. Reload the page to try again."
        />
      )}

      {!isLoading && !isError && items.length === 0 && (
        <EmptyState
          icon={Wrench}
          title="Nothing due"
          description="No service is overdue or falling due in this window."
        />
      )}

      {items.length > 0 && (
        <Card>
          <CardContent className="p-0">
            {items.map((item) => (
              <DueRow key={item.id} item={item} onRecord={() => setRecording(item)} />
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
      <MaintenancePage />
    </RequireRole>
  );
}

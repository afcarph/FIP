'use client';

import {
  ArrowLeft,
  CalendarClock,
  Fuel,
  Gauge,
  Route,
  ShieldCheck,
  TriangleAlert,
  User,
  Wrench,
} from 'lucide-react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import * as React from 'react';

import { EfficiencyHistoryChart } from '@/components/charts/efficiency-history-chart';
import { FuelLevelChart } from '@/components/charts/fuel-level-chart';
import { FuelLevelBar, FuelStatusBadge } from '@/components/fleet/fuel-level';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
  useExpenses,
  useFuelReadings,
  useRecordFuelReading,
  useVehicle,
  useVehicleEfficiency,
} from '@/hooks/use-api';
import {
  formatCurrency,
  formatDate,
  formatDateTime,
  formatDistance,
  formatEfficiency,
  formatLitres,
  formatNumber,
  formatPercent,
} from '@/lib/utils';
import type { FuelPurchase, Vehicle, VehicleEfficiency } from '@/types/api';

export default function VehicleDetailPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);

  const { data: vehicle, isLoading, isError } = useVehicle(id);
  const { data: efficiency, isLoading: efficiencyLoading } = useVehicleEfficiency(id);
  const { data: fillUps, isLoading: fillUpsLoading } = useExpenses({ vehicle_id: id, per_page: 25 });
  const { data: readings, isLoading: readingsLoading } = useFuelReadings(id, { per_page: 200 });

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <div className="grid gap-4 lg:grid-cols-3">
          {[0, 1, 2].map((key) => (
            <Skeleton key={key} className="h-40 w-full" />
          ))}
        </div>
        <Skeleton className="h-72 w-full" />
      </div>
    );
  }

  if (isError || !vehicle) {
    return (
      <EmptyState
        icon={TriangleAlert}
        title="Vehicle not found"
        description="It may have been removed, or it belongs to another company."
        action={
          <Button asChild size="sm" variant="outline">
            <Link href="/vehicles">Back to vehicles</Link>
          </Button>
        }
      />
    );
  }

  return (
    <div className="space-y-6">
      <header className="space-y-3">
        <Link
          href="/vehicles"
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
          Vehicles
        </Link>

        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{vehicle.display_name}</h1>
            <p className="text-sm text-muted-foreground">
              {vehicle.plate_number}
              {vehicle.fuel_type ? ` · ${vehicle.fuel_type.name}` : ''}
              {vehicle.fleet ? ` · ${vehicle.fleet.name}` : ''}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={vehicle.status === 'active' ? 'outline' : 'secondary'}>
              {vehicle.status.replace(/_/g, ' ')}
            </Badge>
            <FuelStatusBadge status={vehicle.fuel.status} isStale={vehicle.fuel.is_stale} />
          </div>
        </div>
      </header>

      <div className="grid gap-4 lg:grid-cols-3">
        <FuelCard vehicle={vehicle} />
        <EfficiencyCard vehicle={vehicle} efficiency={efficiency} />
        <AssignmentCard vehicle={vehicle} />
      </div>

      <FuelLevelChart history={readings} loading={readingsLoading} />

      <EfficiencyHistoryChart data={efficiency} loading={efficiencyLoading} />

      <FillUpHistory rows={fillUps ?? []} loading={fillUpsLoading} />

      <div className="grid gap-4 lg:grid-cols-2">
        <DocumentsCard vehicle={vehicle} />
        <MaintenanceCard vehicle={vehicle} />
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ cards ---

function FuelCard({ vehicle }: { vehicle: Vehicle }) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <Fuel className="size-4" aria-hidden="true" />
          Fuel
        </CardTitle>
        <CardDescription>
          {vehicle.fuel.recorded_at
            ? `Last reading ${formatDateTime(vehicle.fuel.recorded_at)}`
            : 'No reading recorded yet'}
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <FuelLevelBar
          percentage={vehicle.fuel.current_percentage}
          litres={vehicle.fuel.current_litres}
          status={vehicle.fuel.status}
        />

        <dl className="grid grid-cols-2 gap-3 text-sm">
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">Tank</dt>
            <dd className="tabular font-medium">{formatLitres(vehicle.tank_capacity)}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">Est. range</dt>
            <dd className="tabular font-medium">
              {formatDistance(vehicle.efficiency.estimated_range_km)}
            </dd>
          </div>
        </dl>

        <RecordReadingForm vehicleId={vehicle.id} />
      </CardContent>
    </Card>
  );
}

function EfficiencyCard({ vehicle, efficiency }: { vehicle: Vehicle; efficiency?: VehicleEfficiency }) {
  const deviation = vehicle.efficiency.deviation_pct;
  const trips = efficiency?.from_trips ?? null;

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <Gauge className="size-4" aria-hidden="true" />
          Efficiency
        </CardTitle>
        <CardDescription>Rolling average against this vehicle&apos;s own baseline</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <p className="tabular text-3xl font-semibold">
          {formatEfficiency(vehicle.efficiency.avg_km_per_litre)}
        </p>

        {/*
          Distance, not economy. Trips know how far they went; they do not know
          what share of this vehicle's driving they represent, so turning that
          into km/L produced a figure that read as a failing engine on the first
          real vehicle it met.
        */}
        {trips ? (
          <div className="rounded-md border border-border bg-muted/40 px-3 py-2">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">
              Recorded on trips
            </p>
            <p className="tabular text-lg font-semibold">{formatDistance(trips.distance_km)}</p>
            <p className="text-xs text-muted-foreground">
              over {trips.trips} completed trip{trips.trips === 1 ? '' : 's'} in 90 days
            </p>
          </div>
        ) : null}

        <dl className="grid grid-cols-2 gap-3 text-sm">
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">Baseline</dt>
            <dd className="tabular font-medium">
              {formatEfficiency(vehicle.efficiency.baseline_km_per_litre)}
            </dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">Deviation</dt>
            <dd
              className={
                // Exactly on baseline is neither good nor bad, so it stays
                // neutral rather than borrowing the "better than expected"
                // colour from a value that is only ever zero.
                deviation == null || deviation === 0
                  ? 'tabular font-medium'
                  : deviation < 0
                    ? 'tabular font-medium text-destructive'
                    : 'tabular font-medium text-price-down'
              }
            >
              {/* formatPercent already carries the sign — prefixing another
                  produced "++7.2%". */}
              {formatPercent(deviation)}
            </dd>
          </div>
        </dl>

        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Route className="size-4 shrink-0" aria-hidden="true" />
          <span className="tabular">{formatDistance(vehicle.current_odometer)} on the clock</span>
        </div>
      </CardContent>
    </Card>
  );
}

function AssignmentCard({ vehicle }: { vehicle: Vehicle }) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <User className="size-4" aria-hidden="true" />
          Assignment
        </CardTitle>
        <CardDescription>Who is currently responsible for this vehicle</CardDescription>
      </CardHeader>

      <CardContent className="space-y-3 text-sm">
        {vehicle.assigned_driver ? (
          <p className="font-medium">{vehicle.assigned_driver.name}</p>
        ) : (
          <p className="text-muted-foreground">No driver assigned</p>
        )}

        <dl className="grid grid-cols-2 gap-3">
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">Fleet</dt>
            <dd className="font-medium">{vehicle.fleet?.name ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">Type</dt>
            <dd className="font-medium capitalize">{vehicle.vehicle_type}</dd>
          </div>
        </dl>
      </CardContent>
    </Card>
  );
}

function DocumentsCard({ vehicle }: { vehicle: Vehicle }) {
  const rows = [
    {
      label: 'Registration',
      date: vehicle.documents.registration_expiry,
      days: vehicle.documents.registration_expires_in_days,
    },
    {
      label: 'Insurance',
      date: vehicle.documents.insurance_expiry,
      days: vehicle.documents.insurance_expires_in_days,
    },
  ];

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <ShieldCheck className="size-4" aria-hidden="true" />
          Documents
        </CardTitle>
        <CardDescription>{vehicle.documents.insurance_provider ?? 'No insurer on file'}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-3 text-sm">
        {rows.map((row) => (
          <div key={row.label} className="flex items-center justify-between gap-3">
            <span className="text-muted-foreground">{row.label}</span>
            <span className="flex items-center gap-2">
              <span className="tabular">{row.date ? formatDate(row.date) : '—'}</span>
              {/* null days means nothing on file, which must not read as overdue. */}
              {row.days !== null && row.days <= 60 ? (
                <Badge variant={row.days < 0 ? 'destructive' : 'warning'}>
                  {row.days < 0 ? 'Expired' : `${Math.round(row.days)}d`}
                </Badge>
              ) : null}
            </span>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function MaintenanceCard({ vehicle }: { vehicle: Vehicle }) {
  const due = vehicle.maintenance ?? [];

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <Wrench className="size-4" aria-hidden="true" />
          Maintenance
        </CardTitle>
        <CardDescription>Work due or overdue</CardDescription>
      </CardHeader>

      <CardContent className="space-y-2 text-sm">
        {due.length === 0 ? (
          <p className="text-muted-foreground">Nothing due.</p>
        ) : (
          due.map((item) => (
            <div key={item.service} className="flex items-center justify-between gap-3">
              <span>{item.service}</span>
              <span className="flex items-center gap-2">
                <span className="tabular text-muted-foreground">
                  {item.due_at ? formatDate(item.due_at) : '—'}
                </span>
                <Badge variant={item.status === 'overdue' ? 'destructive' : 'warning'}>
                  {item.status.replace(/_/g, ' ')}
                </Badge>
              </span>
            </div>
          ))
        )}
      </CardContent>
    </Card>
  );
}

// ------------------------------------------------------------- fill-up log ---

function FillUpHistory({
  rows,
  loading,
}: {
  rows: FuelPurchase[];
  loading: boolean;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <CalendarClock className="size-4" aria-hidden="true" />
          Fill-up history
        </CardTitle>
        <CardDescription>Every recorded fuel purchase for this vehicle</CardDescription>
      </CardHeader>

      <CardContent className="px-0">
        {loading ? (
          <div className="space-y-2 px-6">
            {[0, 1, 2].map((key) => (
              <Skeleton key={key} className="h-10 w-full" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={Fuel}
            title="No fill-ups yet"
            description="Log one from the Expenses screen and it will appear here."
            className="py-10"
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
                  <th scope="col" className="px-6 py-2 text-left font-medium">Date</th>
                  <th scope="col" className="px-3 py-2 text-left font-medium">Station</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Litres</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">₱/L</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Total</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Odometer</th>
                  <th scope="col" className="px-6 py-2 text-right font-medium">km/L</th>
                </tr>
              </thead>

              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-border/50 last:border-0">
                    <td className="px-6 py-3">
                      <span className="tabular">{formatDate(row.purchased_at)}</span>
                      {row.is_flagged ? (
                        <Badge variant="destructive" className="ml-2">
                          <TriangleAlert className="size-3" aria-hidden="true" />
                          Anomaly
                        </Badge>
                      ) : null}
                    </td>
                    <td className="px-3 py-3">
                      {row.station ? (
                        <>
                          <span>{row.station.name}</span>
                          {row.station.brand ? (
                            <span className="text-muted-foreground"> · {row.station.brand}</span>
                          ) : null}
                        </>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </td>
                    <td className="tabular px-3 py-3 text-right">{formatNumber(row.litres, 2)}</td>
                    <td className="tabular px-3 py-3 text-right">
                      {formatCurrency(row.price_per_litre)}
                    </td>
                    <td className="tabular px-3 py-3 text-right font-medium">
                      {formatCurrency(row.total_cost)}
                    </td>
                    <td className="tabular px-3 py-3 text-right text-muted-foreground">
                      {row.odometer != null ? formatDistance(row.odometer) : '—'}
                    </td>
                    <td className="tabular px-6 py-3 text-right">
                      {formatEfficiency(row.km_per_litre)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------- record reading ---

function RecordReadingForm({ vehicleId }: { vehicleId: number }) {
  const [value, setValue] = React.useState('');
  const recordReading = useRecordFuelReading(vehicleId);

  const parsed = Number(value);
  const isValid = value !== '' && Number.isFinite(parsed) && parsed >= 0 && parsed <= 100;

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    if (!isValid) return;

    recordReading.mutate(
      { fuel_pct: parsed },
      { onSuccess: () => setValue('') },
    );
  };

  return (
    <form onSubmit={submit} className="space-y-2 border-t pt-4">
      <Label htmlFor="fuel_pct" className="text-xs uppercase tracking-wide text-muted-foreground">
        Record a reading
      </Label>

      <div className="flex items-center gap-2">
        <Input
          id="fuel_pct"
          type="number"
          inputMode="decimal"
          min={0}
          max={100}
          step="0.1"
          placeholder="Tank %"
          value={value}
          onChange={(event) => setValue(event.target.value)}
          aria-describedby="fuel_pct_help"
        />
        <Button type="submit" size="sm" disabled={!isValid || recordReading.isPending}>
          {recordReading.isPending ? 'Saving…' : 'Save'}
        </Button>
      </div>

      <p id="fuel_pct_help" className="text-xs text-muted-foreground" role="status" aria-live="polite">
        {recordReading.isError
          ? 'That reading was rejected. Check the value and try again.'
          : recordReading.isSuccess
            ? 'Reading recorded.'
            : 'A percentage between 0 and 100, as shown on the gauge.'}
      </p>
    </form>
  );
}

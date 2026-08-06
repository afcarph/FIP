'use client';

import { AlertTriangle, Plus, Receipt } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { ExpenseChart } from '@/components/charts/expense-chart';
import { StatCard } from '@/components/dashboard/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useExpenses, useExpenseSummary, useVehicles } from '@/hooks/use-api';
import {
  formatCompactCurrency,
  formatCurrency,
  formatDateTime,
  formatDistance,
  formatEfficiency,
  formatLitres,
} from '@/lib/utils';

export default function ExpensesPage() {
  const [vehicleId, setVehicleId] = React.useState<number | undefined>();

  const { data: vehicles } = useVehicles();
  const { data: summary, isLoading: summaryLoading } = useExpenseSummary({ vehicle_id: vehicleId });
  const { data: purchases, isLoading } = useExpenses({ vehicle_id: vehicleId, per_page: 50 });

  return (
    <div className="space-y-6">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Fuel expenses</h1>
          <p className="text-sm text-muted-foreground">Every fill-up, with efficiency worked out for you</p>
        </div>

        <div className="flex gap-2">
          {/* An Export button linked to /reports, which does not exist. A
              control that 404s is worse than no control, so it is gone until
              there is a reports page for it to open. */}
          <Button asChild size="sm">
            <Link href="/expenses/new">
              <Plus aria-hidden="true" />
              Log a fill-up
            </Link>
          </Button>
        </div>
      </header>

      {vehicles && vehicles.length > 1 ? (
        <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by vehicle">
          <button
            type="button"
            onClick={() => setVehicleId(undefined)}
            aria-pressed={vehicleId === undefined}
            className={chipClass(vehicleId === undefined)}
          >
            All vehicles
          </button>
          {vehicles.map((vehicle) => (
            <button
              key={vehicle.id}
              type="button"
              onClick={() => setVehicleId(vehicle.id)}
              aria-pressed={vehicleId === vehicle.id}
              className={chipClass(vehicleId === vehicle.id)}
            >
              {vehicle.display_name}
            </button>
          ))}
        </div>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Total spend"
          value={formatCompactCurrency(summary?.summary.total_cost)}
          hint={`${summary?.summary.fill_ups ?? 0} fill-ups`}
          loading={summaryLoading}
        />
        <StatCard
          label="Fuel purchased"
          value={formatLitres(summary?.summary.total_litres)}
          hint={
            summary?.summary.avg_price_per_litre
              ? `${formatCurrency(summary.summary.avg_price_per_litre)}/L average`
              : undefined
          }
          loading={summaryLoading}
          accent="warning"
        />
        <StatCard
          label="Cost per kilometre"
          value={
            summary?.summary.avg_cost_per_km
              ? `${formatCurrency(summary.summary.avg_cost_per_km, 2)}/km`
              : '—'
          }
          hint={formatDistance(summary?.summary.total_distance_km)}
          loading={summaryLoading}
          accent="success"
        />
        <StatCard
          label="Missed savings"
          value={formatCompactCurrency(summary?.savings.potential_savings)}
          hint={
            summary?.savings.savings_pct
              ? `${summary.savings.savings_pct.toFixed(1)}% of spend`
              : undefined
          }
          loading={summaryLoading}
          accent="danger"
        />
      </div>

      <ExpenseChart data={summary?.monthly_series ?? []} loading={summaryLoading} />

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Fill-up history</CardTitle>
        </CardHeader>

        <CardContent className="px-0">
          {isLoading ? (
            <div className="space-y-3 px-5">
              {Array.from({ length: 5 }).map((_, index) => (
                <Skeleton key={index} className="h-14 w-full" />
              ))}
            </div>
          ) : purchases?.length ? (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead>
                  <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
                    <th scope="col" className="px-5 py-2 text-left font-medium">Date</th>
                    <th scope="col" className="px-3 py-2 text-left font-medium">Vehicle</th>
                    <th scope="col" className="px-3 py-2 text-left font-medium">Station</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Litres</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">₱/L</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Total</th>
                    <th scope="col" className="px-5 py-2 text-right font-medium">km/L</th>
                  </tr>
                </thead>

                <tbody>
                  {purchases.map((purchase) => (
                    <tr
                      key={purchase.id}
                      className="border-b border-border/50 last:border-0 hover:bg-muted/40"
                    >
                      <td className="whitespace-nowrap px-5 py-3">
                        <div className="flex items-center gap-2">
                          {formatDateTime(purchase.purchased_at)}
                          {purchase.is_flagged ? (
                            <Badge variant="destructive" title="Flagged for review">
                              <AlertTriangle className="size-3" aria-hidden="true" />
                              <span className="sr-only">Flagged as anomalous</span>
                            </Badge>
                          ) : null}
                        </div>
                      </td>
                      <td className="px-3 py-3">{purchase.vehicle?.plate_number ?? '—'}</td>
                      <td className="max-w-40 truncate px-3 py-3 text-muted-foreground">
                        {purchase.station?.name ?? '—'}
                      </td>
                      <td className="tabular px-3 py-3 text-right">{purchase.litres.toFixed(2)}</td>
                      <td className="tabular px-3 py-3 text-right">
                        {formatCurrency(purchase.price_per_litre)}
                      </td>
                      <td className="tabular px-3 py-3 text-right font-medium">
                        {formatCurrency(purchase.total_cost)}
                      </td>
                      <td className="tabular px-5 py-3 text-right">
                        {formatEfficiency(purchase.km_per_litre)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <EmptyState
              icon={Receipt}
              title="No fill-ups logged"
              description="Log your first fill-up and we will start tracking cost per kilometre and efficiency."
              action={
                <Button asChild size="sm">
                  <Link href="/expenses/new">Log a fill-up</Link>
                </Button>
              }
            />
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function chipClass(active: boolean): string {
  return `rounded-full border px-3 py-1.5 text-xs font-medium transition-colors ${
    active
      ? 'border-primary bg-primary/10 text-primary'
      : 'border-border text-muted-foreground hover:bg-accent'
  }`;
}

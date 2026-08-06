'use client';

import { AlertTriangle, Car, Gauge, ShieldAlert, TrendingDown, Users, Wrench } from 'lucide-react';

import { ExpenseChart } from '@/components/charts/expense-chart';
import { RequireRole } from '@/components/auth/require-role';
import { StatCard } from '@/components/dashboard/stat-card';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useFleetDashboard } from '@/hooks/use-api';
import {
  formatCompactCurrency,
  formatCurrency,
  formatEfficiency,
  formatNumber,
  formatRelative,
} from '@/lib/utils';

function FleetPageBody() {
  const { data, isLoading } = useFleetDashboard();

  const severityVariant = (severity: string) =>
    severity === 'critical' || severity === 'high' ? 'destructive' : 'warning';

  return (
    <div className="space-y-6">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Fleet operations</h1>
          <p className="text-sm text-muted-foreground">
            Utilisation, spend, maintenance and fuel-anomaly detection
          </p>
        </div>

        {/* Alerts linked to /fleet/fraud-alerts, which does not exist. The
            open-alert count is still shown in the cards below. */}
      </header>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Active vehicles"
          value={formatNumber(data?.vehicles.active)}
          icon={Car}
          hint={`${data?.vehicles.total ?? 0} registered`}
          loading={isLoading}
        />
        <StatCard
          label="Utilisation"
          value={data ? `${data.utilisation.utilisation_pct.toFixed(0)}%` : '—'}
          icon={Gauge}
          hint={data ? `${data.utilisation.idle} idle this month` : undefined}
          loading={isLoading}
          accent={data && data.utilisation.utilisation_pct < 70 ? 'warning' : 'success'}
        />
        <StatCard
          label="Fuel spend"
          value={formatCompactCurrency(data?.summary.total_cost)}
          icon={TrendingDown}
          hint={`${data?.summary.fill_ups ?? 0} fill-ups this month`}
          loading={isLoading}
        />
        <StatCard
          label="Drivers"
          value={formatNumber(data?.drivers.active)}
          icon={Users}
          hint={
            data?.drivers.licence_expiring_30d
              ? `${data.drivers.licence_expiring_30d} licence(s) expiring`
              : undefined
          }
          loading={isLoading}
          accent={data?.drivers.licence_expiring_30d ? 'warning' : 'primary'}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <ExpenseChart data={data?.monthly_series ?? []} loading={isLoading} />
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <ShieldAlert className="size-4 text-destructive" aria-hidden="true" />
              Fuel anomalies
            </CardTitle>
            <CardDescription>Open alerts needing review</CardDescription>
          </CardHeader>

          <CardContent className="space-y-3">
            {isLoading ? (
              Array.from({ length: 3 }).map((_, index) => <Skeleton key={index} className="h-12 w-full" />)
            ) : data?.fraud_alerts.recent.length ? (
              data.fraud_alerts.recent.map((alert) => (
                <div key={alert.id} className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium capitalize">
                      {alert.type.replace(/_/g, ' ')}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                      {alert.vehicle ?? 'Unknown vehicle'}
                      {alert.driver ? ` · ${alert.driver}` : ''} ·{' '}
                      {formatRelative(alert.detected_at)}
                    </p>
                  </div>
                  <Badge variant={severityVariant(alert.severity)} className="shrink-0 capitalize">
                    {alert.severity}
                  </Badge>
                </div>
              ))
            ) : (
              <EmptyState
                icon={ShieldAlert}
                title="No open alerts"
                description="Nothing anomalous in your recent fuel transactions."
                className="py-8"
              />
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Highest fuel spend</CardTitle>
            <CardDescription>By vehicle, this month</CardDescription>
          </CardHeader>

          <CardContent className="px-0">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[420px] text-sm">
                <thead>
                  <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
                    <th scope="col" className="px-5 py-2 text-left font-medium">Vehicle</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Spend</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Litres</th>
                    <th scope="col" className="px-5 py-2 text-right font-medium">km/L</th>
                  </tr>
                </thead>
                <tbody>
                  {(data?.top_consumers ?? []).map((vehicle) => (
                    <tr key={vehicle.vehicle_id} className="border-b border-border/50 last:border-0">
                      <td className="px-5 py-3">
                        <p className="font-medium">{vehicle.plate_number}</p>
                        {vehicle.nickname ? (
                          <p className="text-xs text-muted-foreground">{vehicle.nickname}</p>
                        ) : null}
                      </td>
                      <td className="tabular px-3 py-3 text-right font-medium">
                        {formatCurrency(vehicle.total_cost)}
                      </td>
                      <td className="tabular px-3 py-3 text-right text-muted-foreground">
                        {formatNumber(vehicle.total_litres, 1)}
                      </td>
                      <td className="tabular px-5 py-3 text-right">
                        {formatEfficiency(vehicle.avg_km_per_litre)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Wrench className="size-4" aria-hidden="true" />
              Maintenance
            </CardTitle>
            <CardDescription>Scheduled work across the fleet</CardDescription>
          </CardHeader>

          <CardContent className="grid grid-cols-2 gap-4">
            <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4">
              <div className="mb-1 flex items-center gap-1.5 text-destructive">
                <AlertTriangle className="size-4" aria-hidden="true" />
                <span className="text-xs font-medium uppercase tracking-wide">Overdue</span>
              </div>
              <p className="tabular text-3xl font-semibold">{data?.maintenance.overdue ?? 0}</p>
            </div>

            <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4">
              <div className="mb-1 flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                <Wrench className="size-4" aria-hidden="true" />
                <span className="text-xs font-medium uppercase tracking-wide">Due soon</span>
              </div>
              <p className="tabular text-3xl font-semibold">{data?.maintenance.due_soon ?? 0}</p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

export default function FleetPage() {
  return (
    // Same roles the sidebar filters this entry on — nav and route agreeing is
    // the point; they disagreed before.
    <RequireRole roles={['fleet_manager', 'company_manager', 'super_admin', 'system_admin']}>
      <FleetPageBody />
    </RequireRole>
  );
}

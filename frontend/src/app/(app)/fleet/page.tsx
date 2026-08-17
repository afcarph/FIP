'use client';

import {
  AlertTriangle,
  Building2,
  Car,
  Gauge,
  ShieldAlert,
  TrendingDown,
  Users,
  Wrench,
} from 'lucide-react';
import Link from 'next/link';

import { ExpenseChart } from '@/components/charts/expense-chart';
import { FleetOverview } from '@/components/fleet/fleet-overview';
import { RequireRole } from '@/components/auth/require-role';
import { GettingStarted } from '@/components/fleet/getting-started';
import { StatCard } from '@/components/dashboard/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { ApiError } from '@/lib/api-client';
import { useAuth } from '@/hooks/use-auth';
import { useFleetDashboard, useFleetSubscription } from '@/hooks/use-api';
import {
  formatCompactCurrency,
  formatCurrency,
  formatEfficiency,
  formatNumber,
  formatRelative,
} from '@/lib/utils';

/**
 * `free_trial` is a config key, not a sentence. Rendering it raw put "On the
 * free_trial plan" in front of a customer.
 */
function planLabel(tier?: string): string {
  if (!tier) return 'current';

  return tier
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

/**
 * What the company is using against its plan.
 *
 * Here rather than only in the admin console because a limit nobody can see is
 * a limit met as a refusal, halfway through adding a vehicle. Unlimited is
 * written as "No limit" rather than drawn as a full bar, and the numbers say
 * they are provisional, because they are: no plan has been approved, and a
 * screen that presents them as settled invites somebody to sell against them.
 */
function Capacity() {
  const { data } = useFleetSubscription();

  if (!data?.applies || !data.resources) return null;

  // Recorded plan versus the one actually being applied.
  const awaitingConfirmation =
    data.effective_tier !== undefined && data.tier !== undefined && data.effective_tier !== data.tier;

  const rows = [
    { key: 'vehicles', label: 'Vehicles', usage: data.resources.vehicles },
    { key: 'seats', label: 'People', usage: data.resources.seats },
    { key: 'devices', label: 'Devices', usage: data.resources.devices },
  ];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Plan capacity</CardTitle>
        <CardDescription>
          {/*
            While a plan is being set up the headline must not claim the
            chosen plan's numbers are in force — "On the Enterprise plan"
            above a limit of three reads as Enterprise meaning three.

            The provisional caveat is a separate line rather than a third
            clause here: "being set up" is about this company's own limits,
            while provisional is about ours not being approved yet, and
            chaining two different kinds of "not final" made neither clear.
          */}
          {awaitingConfirmation
            ? `${planLabel(data.tier)} plan · being set up`
            : data.tier
              ? `On the ${planLabel(data.tier)} plan`
              : 'Current usage'}
        </CardDescription>

        {/*
          The plan and the numbers can honestly disagree. An enterprise
          agreement is negotiated, so until somebody confirms it the standard
          allowance applies — and a card that said "on the enterprise plan"
          above a limit of three would read as enterprise meaning three.
        */}
        {awaitingConfirmation ? (
          <p className="text-sm text-amber-700 dark:text-amber-500">
            We are confirming the limits agreed with you. Until then your account runs on the{' '}
            {planLabel(data.effective_tier)} allowance shown below — nothing you add now is affected
            when your own limits take effect.
          </p>
        ) : null}

        {data.trial_ends_at && !data.trial_expired ? (
          <p className="text-sm text-muted-foreground">
            Your trial ends {formatRelative(data.trial_ends_at)}.
          </p>
        ) : null}

        {data.trial_expired ? (
          <p className="text-sm text-amber-700 dark:text-amber-500">
            Your trial has ended. Nothing has been removed, but new vehicles, people and devices
            cannot be added until you move onto a plan.
          </p>
        ) : null}

        {/*
          The same sentence the registration page uses. The card is read by a
          customer, and "limits are provisional and not yet approved" is a note
          to ourselves about placeholders — true, and not what a customer needs
          to be told. It disappears on its own once the figures are approved.
        */}
        {data.is_provisional ? (
          <p className="text-xs text-muted-foreground">
            Allowances shown are indicative and not final commercial terms.
          </p>
        ) : null}
      </CardHeader>

      <CardContent className="grid gap-4 sm:grid-cols-3">
        {rows.map(({ key, label, usage }) => (
          <div key={key}>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="tabular text-2xl font-semibold">
              {usage.used}
              {usage.limit === null ? (
                <span className="ml-1 text-sm font-normal text-muted-foreground">· no limit</span>
              ) : (
                <span className="ml-1 text-sm font-normal text-muted-foreground">
                  of {usage.limit}
                </span>
              )}
            </p>
            {usage.over_limit ? (
              <p className="text-xs text-amber-700 dark:text-amber-500">
                Over the plan. Nothing has been removed; new ones are refused.
              </p>
            ) : null}
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

/** Time-of-day greeting, from the reader's own clock rather than the server's. */
function greeting(): string {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';

  return 'Good evening';
}

function FleetPageBody() {
  const { data, isLoading, error } = useFleetDashboard();
  const { user } = useAuth();

  // A platform administrator belongs to no company, which is what makes tenant
  // scoping work — so there is genuinely no fleet to show them. Said plainly,
  // because the alternative was a grid of dashes that looked like an outage.
  const noCompany = error instanceof ApiError && error.code === 'company_required';

  const severityVariant = (severity: string) =>
    severity === 'critical' || severity === 'high' ? 'destructive' : 'warning';

  return (
    <div className="space-y-6">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">
            {greeting()}
            {user?.first_name ? `, ${user.first_name}` : ''}
          </h1>
          <p className="text-sm text-muted-foreground">
            Here&rsquo;s the current state of your fleet.
          </p>
        </div>

        <Button asChild size="sm" variant="outline">
          <Link href="/fleet/alerts">
            <ShieldAlert aria-hidden="true" />
            Review anomalies
          </Link>
        </Button>
      </header>

      {/* Above the figures on purpose: for a company with nothing yet, the
          figures are all zero and the next step is the only useful thing on
          the page. It removes itself once the fleet is set up. */}
      <GettingStarted />

      {noCompany ? (
        <EmptyState
          icon={Building2}
          title="No fleet linked to this account"
          description="Platform administrator accounts are not part of a company, so there is no fleet to show here. Open a company from the admin console, or sign in with an account that belongs to one."
          action={
            <Button asChild size="sm">
              <Link href="/admin/companies">Browse companies</Link>
            </Button>
          }
        />
      ) : null}

      {!noCompany ? (
        <>
          {!noCompany && data?.overview ? <FleetOverview data={data.overview} /> : null}

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
                <CardDescription>
                  Open alerts needing review ·{' '}
                  <Link href="/fleet/alerts" className="text-primary hover:underline">
                    see all
                  </Link>
                </CardDescription>
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

          <Capacity />

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
        </>
      ) : null}
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

'use client';

import {
  AlertTriangle,
  Car,
  Fuel,
  PiggyBank,
  Plus,
  Route,
  TrendingDown,
  Wrench,
} from 'lucide-react';
import Link from 'next/link';

import { ExpenseChart } from '@/components/charts/expense-chart';
import { ForecastCard } from '@/components/dashboard/forecast-card';
import { StatCard } from '@/components/dashboard/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { SkeletonCard } from '@/components/ui/skeleton';
import { useDashboard } from '@/hooks/use-api';
import { useAuth } from '@/hooks/use-auth';
import {
  formatCompactCurrency,
  formatCurrency,
  formatDate,
  formatDistance,
  formatEfficiency,
  formatLitres,
} from '@/lib/utils';

export default function DashboardPage() {
  const { user } = useAuth();
  const { data, isLoading, error } = useDashboard();

  if (error) {
    return (
      <Card>
        <EmptyState
          icon={AlertTriangle}
          title="We could not load your dashboard"
          description={error instanceof Error ? error.message : 'Please try again in a moment.'}
          action={
            <Button onClick={() => window.location.reload()} variant="outline">
              Retry
            </Button>
          }
        />
      </Card>
    );
  }

  const summary = data?.summary;
  const savings = data?.savings;

  // Month-on-month change comes from the last two points of the series.
  const series = data?.monthly_series ?? [];
  const thisMonth = series.at(-1);
  const lastMonth = series.at(-2);
  const spendChange =
    thisMonth && lastMonth && lastMonth.total_cost > 0
      ? ((thisMonth.total_cost - lastMonth.total_cost) / lastMonth.total_cost) * 100
      : null;

  return (
    <div className="space-y-6">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">
            {greeting()}
            {user?.first_name ? `, ${user.first_name}` : ''}
          </h1>
          <p className="text-sm text-muted-foreground">
            Your fuel spend and this week&rsquo;s outlook
          </p>
        </div>

        <div className="flex gap-2">
          <Button asChild variant="outline" size="sm">
            <Link href="/map">
              <Route aria-hidden="true" />
              Find cheap fuel
            </Link>
          </Button>
          <Button asChild size="sm">
            <Link href="/expenses/new">
              <Plus aria-hidden="true" />
              Log a fill-up
            </Link>
          </Button>
        </div>
      </header>

      {/* Headline figures */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Spent this month"
          value={formatCompactCurrency(summary?.total_cost ?? 0)}
          icon={Fuel}
          change={spendChange}
          changeLabel="vs. last month"
          higherIsBetter={false}
          loading={isLoading}
        />
        <StatCard
          label="Fuel purchased"
          value={formatLitres(summary?.total_litres)}
          icon={Fuel}
          hint={`${summary?.fill_ups ?? 0} fill-up${summary?.fill_ups === 1 ? '' : 's'}`}
          loading={isLoading}
          accent="warning"
        />
        <StatCard
          label="Average efficiency"
          value={formatEfficiency(summary?.avg_km_per_litre)}
          icon={Car}
          hint={summary?.total_distance_km ? formatDistance(summary.total_distance_km) : undefined}
          loading={isLoading}
          accent="success"
        />
        <StatCard
          label="Missed savings"
          value={formatCompactCurrency(savings?.potential_savings ?? 0)}
          icon={PiggyBank}
          hint={
            savings?.savings_pct
              ? `${savings.savings_pct.toFixed(1)}% by always using the cheapest station`
              : undefined
          }
          loading={isLoading}
          accent={savings && savings.potential_savings > 500 ? 'danger' : 'success'}
        />
      </div>

      {/* This week's forecast */}
      <section aria-labelledby="forecast-heading">
        <div className="mb-3 flex items-center justify-between">
          <h2 id="forecast-heading" className="text-lg font-semibold tracking-tight">
            This week&rsquo;s forecast
          </h2>
          <Button asChild variant="ghost" size="sm">
            <Link href="/forecasts">View history</Link>
          </Button>
        </div>

        {isLoading ? (
          <div className="grid gap-4 md:grid-cols-3">
            {Array.from({ length: 3 }).map((_, index) => (
              <SkeletonCard key={index} />
            ))}
          </div>
        ) : data?.forecasts?.length ? (
          <div className="grid gap-4 md:grid-cols-3">
            {data.forecasts.slice(0, 3).map((forecast) => (
              <ForecastCard
                key={forecast.fuel_type_id}
                fuelType={forecast.fuel_type}
                direction={forecast.direction}
                changeAmount={forecast.change_amount}
                confidence={forecast.confidence}
                narrative={forecast.narrative}
                effectiveWeek={forecast.effective_week}
                drivers={forecast.drivers}
              />
            ))}
          </div>
        ) : (
          <Card>
            <EmptyState
              icon={TrendingDown}
              title="No forecast this week"
              description="Forecasts are generated every Monday for the Tuesday adjustment."
            />
          </Card>
        )}
      </section>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <ExpenseChart data={series} loading={isLoading} />
        </div>

        <div className="space-y-6">
          {/* Vehicles */}
          <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0">
              <div>
                <CardTitle className="text-base">Your vehicles</CardTitle>
                <CardDescription>Efficiency against baseline</CardDescription>
              </div>
              <Button asChild variant="ghost" size="sm">
                <Link href="/vehicles">All</Link>
              </Button>
            </CardHeader>

            <CardContent className="space-y-3">
              {isLoading ? (
                <SkeletonCard />
              ) : data?.vehicles?.length ? (
                data.vehicles.slice(0, 4).map((vehicle) => (
                  <Link
                    key={vehicle.id}
                    href={`/vehicles/${vehicle.id}`}
                    className="flex items-center justify-between rounded-lg p-2 transition-colors hover:bg-muted/60"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{vehicle.name}</p>
                      <p className="tabular text-xs text-muted-foreground">
                        {vehicle.plate_number} · {formatDistance(vehicle.odometer)}
                      </p>
                    </div>

                    <div className="shrink-0 text-right">
                      <p className="tabular text-sm font-medium">
                        {formatEfficiency(vehicle.avg_km_per_litre)}
                      </p>
                      {vehicle.efficiency_deviation_pct !== null ? (
                        <p
                          className={`tabular text-xs ${
                            vehicle.efficiency_deviation_pct < -5
                              ? 'text-price-up'
                              : 'text-muted-foreground'
                          }`}
                        >
                          {vehicle.efficiency_deviation_pct > 0 ? '+' : ''}
                          {vehicle.efficiency_deviation_pct.toFixed(1)}% vs. baseline
                        </p>
                      ) : null}
                    </div>
                  </Link>
                ))
              ) : (
                <EmptyState
                  icon={Car}
                  title="No vehicles yet"
                  description="Add one to start tracking efficiency."
                  action={
                    <Button asChild size="sm">
                      <Link href="/vehicles/new">Add a vehicle</Link>
                    </Button>
                  }
                />
              )}
            </CardContent>
          </Card>

          {/* Maintenance reminders */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Coming up</CardTitle>
              <CardDescription>Service and renewals due soon</CardDescription>
            </CardHeader>

            <CardContent className="space-y-2.5">
              {isLoading ? (
                <SkeletonCard />
              ) : data?.maintenance_due?.length ? (
                data.maintenance_due.map((item, index) => (
                  <div key={index} className="flex items-center justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{item.service}</p>
                      <p className="truncate text-xs text-muted-foreground">{item.vehicle}</p>
                    </div>
                    <Badge variant={item.status === 'overdue' ? 'destructive' : 'warning'}>
                      {item.status === 'overdue' ? 'Overdue' : formatDate(item.due_at)}
                    </Badge>
                  </div>
                ))
              ) : (
                <EmptyState
                  icon={Wrench}
                  title="Nothing due"
                  description="No service items or renewals in the next two weeks."
                  className="py-8"
                />
              )}
            </CardContent>
          </Card>
        </div>
      </div>

      {savings && savings.potential_savings > 0 ? (
        <Card glass className="border-primary/20">
          <CardContent className="flex flex-col items-start gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
              <div className="rounded-lg bg-primary/10 p-2.5 text-primary">
                <PiggyBank className="size-5" aria-hidden="true" />
              </div>
              <div>
                <p className="font-medium">
                  You could have saved {formatCurrency(savings.potential_savings)} in the last 90 days
                </p>
                <p className="text-sm text-muted-foreground">
                  That is what {savings.sample_size} fill-ups would have cost at the cheapest station
                  in each city.
                </p>
              </div>
            </div>

            <Button asChild className="shrink-0">
              <Link href="/map">Find cheaper fuel</Link>
            </Button>
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}

function greeting(): string {
  const hour = new Date().getHours();

  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';
  return 'Good evening';
}

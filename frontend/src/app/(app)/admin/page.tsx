'use client';

import {
  Activity,
  Brain,
  Building2,
  Car,
  Fuel,
  ShieldCheck,
  TrendingUp,
  Users,
} from 'lucide-react';

import { PriceTrendChart } from '@/components/charts/price-trend-chart';
import { PriceComparisonTable } from '@/components/dashboard/price-comparison-table';
import { RequireRole } from '@/components/auth/require-role';
import { StatCard } from '@/components/dashboard/stat-card';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useExecutiveDashboard } from '@/hooks/use-api';
import { formatCompactCurrency, formatCurrency, formatNumber } from '@/lib/utils';

function AdminDashboardPageBody() {
  const { data, isLoading } = useExecutiveDashboard();

  const platform = data?.platform;
  const accuracy = data?.forecast_accuracy;

  // Growth over the trailing two months, from the monthly signup series.
  const growth = data?.user_growth ?? [];
  const current = growth.at(-1)?.total ?? 0;
  const previous = growth.at(-2)?.total ?? 0;
  const growthPct = previous > 0 ? ((current - previous) / previous) * 100 : null;

  return (
    <div className="space-y-6">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Executive dashboard</h1>
          <p className="text-sm text-muted-foreground">Platform-wide health and market analytics</p>
        </div>

        {/* Moderation queue and AI models both linked to pages that do not
            exist. Removed rather than left to 404 — the counts they carried are
            still visible in the cards below. */}
      </header>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <StatCard
          label="Users"
          value={formatNumber(platform?.users)}
          icon={Users}
          change={growthPct}
          changeLabel="new sign-ups"
          higherIsBetter
          loading={isLoading}
        />
        <StatCard
          label="Active (30d)"
          value={formatNumber(platform?.active_users_30d)}
          icon={Activity}
          hint={
            platform?.users
              ? `${Math.round(((platform.active_users_30d ?? 0) / platform.users) * 100)}% of base`
              : undefined
          }
          loading={isLoading}
          accent="success"
        />
        <StatCard
          label="Companies"
          value={formatNumber(platform?.companies)}
          icon={Building2}
          loading={isLoading}
        />
        <StatCard
          label="Vehicles"
          value={formatNumber(platform?.vehicles)}
          icon={Car}
          loading={isLoading}
        />
        <StatCard
          label="Stations"
          value={formatNumber(platform?.stations)}
          icon={Fuel}
          loading={isLoading}
          accent="warning"
        />
        <StatCard
          label="Fill-ups (30d)"
          value={formatNumber(platform?.fill_ups_30d)}
          icon={TrendingUp}
          loading={isLoading}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <PriceTrendChart
            data={data?.national_trend ?? []}
            loading={isLoading}
            title="National price trend"
            description="RON 95 daily average over six months"
            height={300}
          />
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Brain className="size-4 text-primary" aria-hidden="true" />
              Forecast performance
            </CardTitle>
            <CardDescription>Scored against published DOE adjustments</CardDescription>
          </CardHeader>

          <CardContent className="space-y-4">
            {isLoading ? (
              <Skeleton className="h-32 w-full" />
            ) : accuracy?.samples ? (
              <>
                <div>
                  <p className="text-xs text-muted-foreground">Direction accuracy</p>
                  <p className="tabular text-3xl font-semibold">
                    {Math.round((accuracy.direction_accuracy ?? 0) * 100)}%
                  </p>
                  <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                      className="h-full rounded-full bg-price-down"
                      style={{ width: `${(accuracy.direction_accuracy ?? 0) * 100}%` }}
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3 border-t pt-3 text-sm">
                  <div>
                    <p className="text-xs text-muted-foreground">Mean abs. error</p>
                    <p className="tabular font-medium">{formatCurrency(accuracy.mae)}</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">Sample</p>
                    <p className="tabular font-medium">{accuracy.samples} forecasts</p>
                  </div>
                </div>
              </>
            ) : (
              <p className="text-sm text-muted-foreground">
                No forecasts have been scored yet. Accuracy appears once the DOE publishes an
                adjustment for a forecast week.
              </p>
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <PriceComparisonTable data={data?.price_comparison ?? []} loading={isLoading} />

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Regional movement</CardTitle>
            <CardDescription>Week-on-week change by region</CardDescription>
          </CardHeader>

          <CardContent className="space-y-2.5">
            {isLoading ? (
              Array.from({ length: 5 }).map((_, index) => <Skeleton key={index} className="h-8 w-full" />)
            ) : (
              (data?.regional_movement ?? []).map((region) => (
                <div key={region.region_id} className="flex items-center justify-between gap-3 text-sm">
                  <span className="truncate">{region.region_name}</span>
                  <div className="flex shrink-0 items-center gap-3">
                    <span className="tabular text-muted-foreground">
                      {formatCurrency(region.current_avg)}
                    </span>
                    <span
                      className={`tabular w-16 text-right font-medium ${
                        (region.change ?? 0) > 0
                          ? 'text-price-up'
                          : (region.change ?? 0) < 0
                            ? 'text-price-down'
                            : 'text-muted-foreground'
                      }`}
                    >
                      {region.change !== null
                        ? `${region.change > 0 ? '+' : ''}${formatCurrency(region.change)}`
                        : '—'}
                    </span>
                  </div>
                </div>
              ))
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 md:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <ShieldCheck className="size-4" aria-hidden="true" />
              Community contributions
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <Row label="Awaiting moderation" value={formatNumber(data?.crowd.pending_reports)} />
            <Row label="Approved (30d)" value={formatNumber(data?.crowd.approved_30d)} />
            <Row label="Contributors (30d)" value={formatNumber(data?.crowd.contributors_30d)} />
          </CardContent>
        </Card>

        <Card className="md:col-span-2">
          <CardHeader>
            <CardTitle className="text-base">Aggregate member savings</CardTitle>
            <CardDescription>What members paid versus the market average</CardDescription>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Metric
              label="Spend (30d)"
              value={formatCompactCurrency(data?.aggregate_savings.total_spend_30d)}
            />
            <Metric
              label="Avg. paid"
              value={formatCurrency(data?.aggregate_savings.avg_price_paid)}
            />
            <Metric
              label="Market avg."
              value={formatCurrency(data?.aggregate_savings.market_avg_price)}
            />
            <Metric
              label="Saved"
              value={formatCompactCurrency(data?.aggregate_savings.estimated_savings_30d)}
              highlight
            />
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className="tabular font-medium">{value}</span>
    </div>
  );
}

function Metric({ label, value, highlight }: { label: string; value: string; highlight?: boolean }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={`tabular text-lg font-semibold ${highlight ? 'text-price-down' : ''}`}>{value}</p>
    </div>
  );
}

export default function AdminDashboardPage() {
  return (
    // Same roles the sidebar filters this entry on — nav and route agreeing is
    // the point; they disagreed before.
    <RequireRole roles={['super_admin', 'system_admin']}>
      <AdminDashboardPageBody />
    </RequireRole>
  );
}

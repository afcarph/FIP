'use client';

import { Brain, Target } from 'lucide-react';
import * as React from 'react';

import { ForecastHistoryChart } from '@/components/charts/forecast-chart';
import { PriceTrendChart } from '@/components/charts/price-trend-chart';
import { ForecastCard } from '@/components/dashboard/forecast-card';
import { StatCard } from '@/components/dashboard/stat-card';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { SkeletonCard } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useAdvisories, useForecasts, useFuelTypes, usePriceTrend } from '@/hooks/use-api';
import { formatCurrency, formatDate } from '@/lib/utils';

export default function ForecastsPage() {
  const { data: fuelTypes } = useFuelTypes();
  const { data: forecasts, isLoading } = useForecasts();

  const [selectedFuelId, setSelectedFuelId] = React.useState<number | undefined>();

  // Default to RON 95 — the market's headline grade.
  const activeFuelId =
    selectedFuelId ??
    fuelTypes?.find((fuel) => fuel.code === 'gasoline_ron95')?.id ??
    fuelTypes?.[0]?.id;

  const { data: trend, isLoading: trendLoading } = usePriceTrend(activeFuelId, 180);
  const { data: advisories } = useAdvisories(activeFuelId, 12);

  /*
   * Accuracy is only computed over forecasts that have an actual to compare
   * against. Quoting an accuracy figure that includes unscored predictions
   * would inflate it.
   */
  const scored = (forecasts ?? []).filter((forecast) => forecast.actual_change !== null);
  const correct = scored.filter((forecast) => forecast.was_correct).length;
  const meanError = scored.length
    ? scored.reduce((total, forecast) => total + (forecast.absolute_error ?? 0), 0) / scored.length
    : null;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">Price forecasts</h1>
        <p className="text-sm text-muted-foreground">
          Weekly DOE adjustment predictions, and how they compared with what actually happened
        </p>
      </header>

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Direction accuracy"
          value={scored.length ? `${Math.round((correct / scored.length) * 100)}%` : '—'}
          icon={Target}
          hint={scored.length ? `over ${scored.length} scored forecasts` : 'nothing scored yet'}
          accent="success"
        />
        <StatCard
          label="Active forecasts"
          value={String(forecasts?.length ?? 0)}
          icon={Brain}
          hint="fuel types covered this week"
        />
        <StatCard
          label="Mean error"
          value={meanError !== null ? formatCurrency(meanError) : '—'}
          icon={Target}
          hint="average miss per litre"
          accent="warning"
        />
      </div>

      <Tabs defaultValue="current">
        <TabsList>
          <TabsTrigger value="current">This week</TabsTrigger>
          <TabsTrigger value="history">Adjustment history</TabsTrigger>
          <TabsTrigger value="accuracy">Track record</TabsTrigger>
        </TabsList>

        <TabsContent value="current">
          {isLoading ? (
            <div className="grid gap-4 md:grid-cols-3">
              {Array.from({ length: 3 }).map((_, index) => (
                <SkeletonCard key={index} />
              ))}
            </div>
          ) : forecasts?.length ? (
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {forecasts.map((forecast) => (
                <ForecastCard
                  key={forecast.id}
                  fuelType={forecast.fuel_type.name}
                  direction={forecast.direction}
                  changeAmount={forecast.change_amount}
                  confidence={forecast.confidence}
                  narrative={forecast.narrative}
                  effectiveWeek={forecast.forecast_for}
                  drivers={forecast.drivers}
                />
              ))}
            </div>
          ) : (
            <Card>
              <EmptyState
                icon={Brain}
                title="No forecasts available"
                description="Forecasts are generated every Monday morning for the Tuesday adjustment."
              />
            </Card>
          )}
        </TabsContent>

        <TabsContent value="history" className="space-y-4">
          {fuelTypes?.length ? (
            <div className="flex flex-wrap gap-2" role="group" aria-label="Fuel type">
              {fuelTypes
                .filter((fuel) => ['gasoline', 'diesel'].includes(fuel.category))
                .map((fuel) => (
                  <button
                    key={fuel.id}
                    type="button"
                    onClick={() => setSelectedFuelId(fuel.id)}
                    aria-pressed={activeFuelId === fuel.id}
                    className={`rounded-full border px-3 py-1.5 text-xs font-medium transition-colors ${
                      activeFuelId === fuel.id
                        ? 'border-primary bg-primary/10 text-primary'
                        : 'text-muted-foreground hover:bg-accent'
                    }`}
                  >
                    {fuel.name}
                  </button>
                ))}
            </div>
          ) : null}

          {advisories?.length ? <ForecastHistoryChart advisories={advisories} /> : null}

          <PriceTrendChart
            data={trend ?? []}
            loading={trendLoading}
            title="Six-month price trend"
            description="National average with the station-to-station range behind it"
          />
        </TabsContent>

        <TabsContent value="accuracy">
          <Card>
            <CardHeader>
              <CardTitle>Forecast versus actual</CardTitle>
              <CardDescription>
                Every published forecast, scored once the DOE announced the real adjustment
              </CardDescription>
            </CardHeader>

            <CardContent className="px-0">
              {scored.length ? (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[600px] text-sm">
                    <thead>
                      <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
                        <th scope="col" className="px-5 py-2 text-left font-medium">Week</th>
                        <th scope="col" className="px-3 py-2 text-left font-medium">Fuel</th>
                        <th scope="col" className="px-3 py-2 text-right font-medium">Predicted</th>
                        <th scope="col" className="px-3 py-2 text-right font-medium">Actual</th>
                        <th scope="col" className="px-5 py-2 text-right font-medium">Error</th>
                      </tr>
                    </thead>
                    <tbody>
                      {scored.map((forecast) => (
                        <tr key={forecast.id} className="border-b border-border/50 last:border-0">
                          <td className="px-5 py-3">{formatDate(forecast.forecast_for)}</td>
                          <td className="px-3 py-3">{forecast.fuel_type.name}</td>
                          <td className="tabular px-3 py-3 text-right">
                            {formatCurrency(forecast.change_amount)}
                          </td>
                          <td className="tabular px-3 py-3 text-right">
                            {formatCurrency(forecast.actual_change)}
                          </td>
                          <td
                            className={`tabular px-5 py-3 text-right font-medium ${
                              forecast.was_correct ? 'text-price-down' : 'text-price-up'
                            }`}
                          >
                            {formatCurrency(forecast.absolute_error)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState
                  icon={Target}
                  title="Nothing scored yet"
                  description="Forecasts are scored against the DOE announcement the week after publication."
                />
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}

'use client';

import { Fuel } from 'lucide-react';
import {
  Area,
  AreaChart,
  CartesianGrid,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDateTime, formatLitres } from '@/lib/utils';
import type { FuelReadingHistory } from '@/types/api';

interface FuelLevelChartProps {
  history: FuelReadingHistory | undefined;
  loading?: boolean;
  height?: number;
  /** Level bands, so the chart marks the same thresholds the badges use. */
  lowPct?: number;
  criticalPct?: number;
}

/**
 * Tank level over time.
 *
 * This is the chart the telemetry work exists to make possible: a fill-up
 * ledger can tell you what was bought, but only a level series shows fuel
 * leaving the tank when nobody bought anything.
 *
 * Drawn as an area rather than a line because the quantity is a *level* — the
 * filled region reads as "how much is in there", which a bare stroke does not.
 * The LOW and CRITICAL bands are reference lines so a steep fall can be read
 * against the thresholds that would actually raise an alert.
 */
export function FuelLevelChart({
  history,
  loading = false,
  height = 260,
  lowPct = 25,
  criticalPct = 10,
}: FuelLevelChartProps) {
  if (loading) {
    return (
      <Card>
        <CardHeader>
          <Skeleton className="h-5 w-36" />
        </CardHeader>
        <CardContent>
          <Skeleton style={{ height }} className="w-full" />
        </CardContent>
      </Card>
    );
  }

  const readings = history?.readings ?? [];

  // The largest single drop in the window. Worth surfacing as a figure rather
  // than leaving it to be eyeballed off the curve — it is the number that
  // decides whether something is worth investigating.
  const steepestDrop = readings.reduce(
    (worst, reading) =>
      reading.delta_pct != null && reading.delta_pct < worst ? reading.delta_pct : worst,
    0,
  );

  const simulatedCount = readings.filter((reading) => reading.source === 'simulated').length;

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <CardTitle className="text-base">Fuel level history</CardTitle>
            <CardDescription>Tank level over time, from recorded readings</CardDescription>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {/* Simulated rows are separable in the database, so they should be
                separable on screen too — a demo curve must never be mistaken
                for a real one. */}
            {simulatedCount > 0 ? (
              <Badge variant="secondary">
                {simulatedCount} simulated
              </Badge>
            ) : null}
            {steepestDrop < 0 ? (
              <Badge variant={steepestDrop <= -20 ? 'destructive' : 'outline'}>
                Largest drop {steepestDrop.toFixed(1)} pts
              </Badge>
            ) : null}
          </div>
        </div>
      </CardHeader>

      <CardContent>
        {readings.length === 0 ? (
          <EmptyState
            icon={Fuel}
            title="No fuel readings yet"
            description="Record a reading above, or generate one with the fuel simulator."
            className="py-10"
          />
        ) : (
          <ResponsiveContainer width="100%" height={height}>
            <AreaChart data={readings} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
              <defs>
                <linearGradient id="fuelLevelFill" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="hsl(var(--chart-1))" stopOpacity={0.35} />
                  <stop offset="100%" stopColor="hsl(var(--chart-1))" stopOpacity={0.02} />
                </linearGradient>
              </defs>

              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" vertical={false} />

              <XAxis
                dataKey="recorded_at"
                tickFormatter={(value: string) =>
                  new Date(value).toLocaleTimeString('en-PH', {
                    hour: 'numeric',
                    minute: '2-digit',
                  })
                }
                stroke="hsl(var(--muted-foreground))"
                fontSize={11}
                tickLine={false}
                axisLine={false}
                minTickGap={28}
              />

              {/* Fixed 0–100 domain: the axis is a percentage, and letting it
                  auto-scale would make a drop from 90 to 80 look identical to
                  one from 90 to 10. */}
              <YAxis
                domain={[0, 100]}
                ticks={[0, 25, 50, 75, 100]}
                tickFormatter={(value: number) => `${value}%`}
                stroke="hsl(var(--muted-foreground))"
                fontSize={11}
                tickLine={false}
                axisLine={false}
                // 56, not 48: "100%" lost its leading digit at the narrower
                // width and rendered as ".00%".
                width={56}
              />

              <Tooltip content={<FuelLevelTooltip />} cursor={{ stroke: 'hsl(var(--border))' }} />

              <ReferenceLine
                y={lowPct}
                stroke="hsl(var(--muted-foreground))"
                strokeDasharray="4 4"
                label={{
                  value: 'low',
                  position: 'insideTopRight',
                  fill: 'hsl(var(--muted-foreground))',
                  fontSize: 10,
                }}
              />

              <ReferenceLine
                y={criticalPct}
                stroke="hsl(var(--destructive))"
                strokeDasharray="4 4"
                label={{
                  value: 'critical',
                  position: 'insideTopRight',
                  fill: 'hsl(var(--destructive))',
                  fontSize: 10,
                }}
              />

              <Area
                type="monotone"
                dataKey="fuel_pct"
                name="Tank level"
                stroke="hsl(var(--chart-1))"
                strokeWidth={2}
                fill="url(#fuelLevelFill)"
                dot={false}
                activeDot={{ r: 4, strokeWidth: 0 }}
              />
            </AreaChart>
          </ResponsiveContainer>
        )}
      </CardContent>
    </Card>
  );
}

/** The slice of Recharts' tooltip props this component reads. */
interface TooltipRenderProps<T> {
  active?: boolean;
  payload?: Array<{ payload: T }>;
}

function FuelLevelTooltip({
  active,
  payload,
}: TooltipRenderProps<FuelReadingHistory['readings'][number]>) {
  if (!active || !payload?.length) return null;

  const reading = payload[0]!.payload;
  const delta = reading.delta_pct;

  return (
    <div className="glass rounded-lg px-3 py-2 shadow-glass">
      <p className="mb-1.5 text-xs font-medium text-muted-foreground">
        {formatDateTime(reading.recorded_at)}
      </p>

      <div className="space-y-0.5 text-sm">
        <p className="tabular font-semibold">{reading.fuel_pct.toFixed(1)}%</p>

        {reading.fuel_litres != null ? (
          <p className="tabular text-xs text-muted-foreground">
            {formatLitres(reading.fuel_litres)}
          </p>
        ) : null}

        {delta != null ? (
          <p
            className={
              delta < 0 ? 'tabular text-xs text-destructive' : 'tabular text-xs text-price-down'
            }
          >
            {delta > 0 ? '+' : ''}
            {delta.toFixed(1)} pts
            {reading.fuel_purchase_id != null ? ' · refill' : ''}
          </p>
        ) : null}

        {reading.source !== 'manual' ? (
          <p className="text-xs capitalize text-muted-foreground">{reading.source}</p>
        ) : null}
      </div>
    </div>
  );
}

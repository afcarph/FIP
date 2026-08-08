'use client';

import {
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCurrency, formatDate, formatEfficiency } from '@/lib/utils';
import { Fuel } from 'lucide-react';
import type { VehicleEfficiency } from '@/types/api';

interface EfficiencyHistoryChartProps {
  data: VehicleEfficiency | undefined;
  loading?: boolean;
  height?: number;
}

/**
 * Efficiency per fill-up, with the price paid overlaid.
 *
 * Same pairing as the fleet spend chart, one level down: km/L answers "is this
 * vehicle getting worse?" and ₱/L answers "or did fuel just get dearer?".
 * Reading either alone produces the wrong conclusion about half the time.
 *
 * The baseline is drawn as a reference line rather than a third series — it is
 * a constant the vehicle was commissioned with, not something that varies per
 * fill-up, and plotting it as a line would imply it moves.
 */
export function EfficiencyHistoryChart({ data, loading = false, height = 280 }: EfficiencyHistoryChartProps) {
  if (loading) {
    return (
      <Card>
        <CardHeader>
          <Skeleton className="h-5 w-40" />
        </CardHeader>
        <CardContent>
          <Skeleton style={{ height }} className="w-full" />
        </CardContent>
      </Card>
    );
  }

  const series = data?.series ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Efficiency history</CardTitle>
        <CardDescription>Kilometres per litre against the price paid, per fill-up</CardDescription>
      </CardHeader>

      <CardContent>
        {series.length === 0 ? (
          <EmptyState
            icon={Fuel}
            title="No fill-ups recorded"
            description="Efficiency is calculated between two full tanks, so it appears after the second one."
            className="py-10"
          />
        ) : (
          <ResponsiveContainer width="100%" height={height}>
            <ComposedChart data={series} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" vertical={false} />

              <XAxis
                dataKey="date"
                tickFormatter={(value: string) =>
                  new Date(value).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
                }
                stroke="hsl(var(--muted-foreground))"
                fontSize={11}
                tickLine={false}
                axisLine={false}
              />

              <YAxis
                yAxisId="kpl"
                // One decimal and a wider gutter: the auto domain can land on
                // a value like 18.64, which was being clipped to "8.64" — a
                // legible number that happens to be wrong by ten.
                tickFormatter={(value: number) => value.toFixed(1)}
                stroke="hsl(var(--muted-foreground))"
                fontSize={11}
                tickLine={false}
                axisLine={false}
                width={48}
                domain={['dataMin - 1', 'dataMax + 1']}
              />

              <YAxis
                yAxisId="price"
                orientation="right"
                tickFormatter={(value: number) => `₱${value.toFixed(0)}`}
                stroke="hsl(var(--muted-foreground))"
                fontSize={11}
                tickLine={false}
                axisLine={false}
                width={44}
                domain={['dataMin - 2', 'dataMax + 2']}
              />

              <Tooltip content={<EfficiencyHistoryTooltip />} cursor={{ stroke: 'hsl(var(--border))' }} />

              {data?.baseline_km_per_litre ? (
                <ReferenceLine
                  yAxisId="kpl"
                  y={data.baseline_km_per_litre}
                  stroke="hsl(var(--muted-foreground))"
                  strokeDasharray="4 4"
                  label={{
                    value: 'baseline',
                    position: 'insideTopLeft',
                    fill: 'hsl(var(--muted-foreground))',
                    fontSize: 10,
                  }}
                />
              ) : null}

              <Line
                yAxisId="kpl"
                type="monotone"
                dataKey="km_per_litre"
                name="km/L"
                stroke="hsl(var(--chart-1))"
                strokeWidth={2}
                dot={{ r: 3, strokeWidth: 0 }}
                connectNulls
              />

              <Line
                yAxisId="price"
                type="monotone"
                dataKey="price_per_litre"
                name="₱/L paid"
                stroke="hsl(var(--chart-3))"
                strokeWidth={2}
                strokeDasharray="4 3"
                dot={false}
              />

              <Legend
                verticalAlign="bottom"
                height={28}
                wrapperStyle={{ fontSize: 11, color: 'hsl(var(--muted-foreground))' }}
              />
            </ComposedChart>
          </ResponsiveContainer>
        )}
      </CardContent>
    </Card>
  );
}

/** The slice of Recharts' tooltip props this component reads. */
interface TooltipRenderProps<T> {
  active?: boolean;
  label?: string | number;
  payload?: Array<{ payload: T }>;
}

function EfficiencyHistoryTooltip({
  active,
  payload,
  label,
}: TooltipRenderProps<VehicleEfficiency['series'][number]>) {
  if (!active || !payload?.length) return null;

  const point = payload[0]!.payload;

  return (
    <div className="glass rounded-lg px-3 py-2 shadow-glass">
      <p className="mb-1.5 text-xs font-medium text-muted-foreground">{formatDate(String(label))}</p>
      <div className="space-y-0.5 text-sm">
        <p className="tabular font-semibold">{formatEfficiency(point.km_per_litre)}</p>
        <p className="tabular text-xs text-muted-foreground">
          {formatCurrency(point.price_per_litre)}/L paid
        </p>
        {point.cost_per_km != null ? (
          <p className="tabular text-xs text-muted-foreground">
            {formatCurrency(point.cost_per_km, 2)}/km
          </p>
        ) : null}
      </div>
    </div>
  );
}

'use client';

import * as React from 'react';
import {
  Area,
  AreaChart,
  CartesianGrid,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { TrendPoint } from '@/types/api';

interface PriceTrendChartProps {
  data: TrendPoint[];
  title?: string;
  description?: string;
  loading?: boolean;
  showRange?: boolean;
  height?: number;
}

/**
 * National average pump price over time, with the min–max band behind it.
 *
 * The band is the point of this chart: a ₱4 spread between the cheapest and
 * dearest station tells a motorist far more than the average alone, because
 * the spread is the money they can actually capture by choosing where to fill.
 *
 * The y-axis is deliberately *not* zero-based — fuel prices move within a
 * narrow band and a zero baseline would flatten every real movement into a
 * straight line.
 */
export function PriceTrendChart({
  data,
  title = 'Price trend',
  description,
  loading = false,
  showRange = true,
  height = 320,
}: PriceTrendChartProps) {
  const domain = React.useMemo(() => {
    if (!data.length) return ['auto', 'auto'] as const;

    const values = data.flatMap((point) => [point.min_price, point.max_price, point.avg_price]);
    const low = Math.min(...values);
    const high = Math.max(...values);
    const padding = Math.max((high - low) * 0.12, 0.5);

    return [Number((low - padding).toFixed(2)), Number((high + padding).toFixed(2))] as const;
  }, [data]);

  const movement = React.useMemo(() => {
    if (data.length < 2) return null;

    const first = data[0]!.avg_price;
    const last = data[data.length - 1]!.avg_price;

    return { change: last - first, pct: ((last - first) / first) * 100 };
  }, [data]);

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

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between space-y-0">
        <div>
          <CardTitle>{title}</CardTitle>
          {description ? <CardDescription>{description}</CardDescription> : null}
        </div>

        {movement ? (
          <div className="text-right">
            <p
              className={`tabular text-lg font-semibold ${
                movement.change > 0 ? 'text-price-up' : movement.change < 0 ? 'text-price-down' : ''
              }`}
            >
              {movement.change > 0 ? '+' : ''}
              {formatCurrency(movement.change)}
            </p>
            <p className="text-xs text-muted-foreground">over the period</p>
          </div>
        ) : null}
      </CardHeader>

      <CardContent>
        <ResponsiveContainer width="100%" height={height}>
          <AreaChart data={data} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
            <defs>
              <linearGradient id="priceRange" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="hsl(var(--chart-1))" stopOpacity={0.18} />
                <stop offset="100%" stopColor="hsl(var(--chart-1))" stopOpacity={0.02} />
              </linearGradient>
            </defs>

            <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" vertical={false} />

            <XAxis
              dataKey="date"
              tickFormatter={(value: string) =>
                new Date(value).toLocaleDateString('en-PH', { day: 'numeric', month: 'short' })
              }
              stroke="hsl(var(--muted-foreground))"
              fontSize={11}
              tickLine={false}
              axisLine={false}
              minTickGap={32}
            />

            <YAxis
              domain={domain as [number, number]}
              tickFormatter={(value: number) => `₱${value.toFixed(0)}`}
              stroke="hsl(var(--muted-foreground))"
              fontSize={11}
              tickLine={false}
              axisLine={false}
              width={48}
            />

            <Tooltip content={<PriceTooltip />} cursor={{ stroke: 'hsl(var(--border))' }} />

            {showRange ? (
              <>
                <Area
                  type="monotone"
                  dataKey="max_price"
                  stroke="none"
                  fill="url(#priceRange)"
                  name="Highest"
                  isAnimationActive={false}
                />
                <Area
                  type="monotone"
                  dataKey="min_price"
                  stroke="none"
                  fill="hsl(var(--background))"
                  name="Lowest"
                  isAnimationActive={false}
                />
              </>
            ) : null}

            <Line
              type="monotone"
              dataKey="avg_price"
              stroke="hsl(var(--chart-1))"
              strokeWidth={2.25}
              dot={false}
              activeDot={{ r: 4, strokeWidth: 2 }}
              name="National average"
            />

            <Legend
              verticalAlign="bottom"
              height={28}
              iconType="line"
              wrapperStyle={{ fontSize: 11, color: 'hsl(var(--muted-foreground))' }}
            />
          </AreaChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

/** The slice of Recharts' tooltip props this component actually reads. */
interface TooltipRenderProps<T> {
  active?: boolean;
  label?: string | number;
  payload?: Array<{ payload: T }>;
}

function PriceTooltip({ active, payload }: TooltipRenderProps<TrendPoint>) {
  if (!active || !payload?.length) return null;

  // Read the date off the datum rather than the axis label — the label is
  // loosely typed by Recharts and can arrive as a number.
  const point = payload[0]!.payload;

  return (
    <div className="glass rounded-lg px-3 py-2 shadow-glass">
      <p className="mb-1.5 text-xs font-medium text-muted-foreground">{formatDate(point.date)}</p>
      <div className="space-y-0.5 text-sm">
        <p className="tabular font-semibold">{formatCurrency(point.avg_price)} average</p>
        <p className="tabular text-xs text-muted-foreground">
          {formatCurrency(point.min_price)} – {formatCurrency(point.max_price)} range
        </p>
        {point.samples ? (
          <p className="text-xs text-muted-foreground">{point.samples} stations reporting</p>
        ) : null}
      </div>
    </div>
  );
}

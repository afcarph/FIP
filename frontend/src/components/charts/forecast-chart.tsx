'use client';

import { TrendingDown, TrendingUp, Minus } from 'lucide-react';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { Advisory } from '@/types/api';

interface ForecastChartProps {
  advisories: Advisory[];
  height?: number;
}

/**
 * Weekly DOE adjustment history as a diverging bar chart around zero.
 *
 * Increases point up in the warm colour, rollbacks point down in the cool one,
 * and the zero reference line makes the direction readable at a glance without
 * relying on colour alone.
 */
export function ForecastHistoryChart({ advisories, height = 260 }: ForecastChartProps) {
  const data = [...advisories]
    .reverse()
    .map((advisory) => ({ ...advisory, label: formatDate(advisory.week_start) }));

  const cumulative = data.reduce((total, point) => total + point.change_amount, 0);

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between space-y-0">
        <div>
          <CardTitle>Weekly adjustments</CardTitle>
          <CardDescription>DOE oil price movements, most recent last</CardDescription>
        </div>

        <div className="text-right">
          <p
            className={`tabular flex items-center gap-1 text-lg font-semibold ${
              cumulative > 0 ? 'text-price-up' : cumulative < 0 ? 'text-price-down' : ''
            }`}
          >
            {cumulative > 0 ? (
              <TrendingUp className="size-4" aria-hidden="true" />
            ) : cumulative < 0 ? (
              <TrendingDown className="size-4" aria-hidden="true" />
            ) : (
              <Minus className="size-4" aria-hidden="true" />
            )}
            {cumulative > 0 ? '+' : ''}
            {formatCurrency(cumulative)}
          </p>
          <p className="text-xs text-muted-foreground">net over {data.length} weeks</p>
        </div>
      </CardHeader>

      <CardContent>
        <ResponsiveContainer width="100%" height={height}>
          <BarChart data={data} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" vertical={false} />

            <XAxis
              dataKey="label"
              stroke="hsl(var(--muted-foreground))"
              fontSize={10}
              tickLine={false}
              axisLine={false}
              minTickGap={20}
            />

            <YAxis
              tickFormatter={(value: number) => `₱${value.toFixed(2)}`}
              stroke="hsl(var(--muted-foreground))"
              fontSize={11}
              tickLine={false}
              axisLine={false}
              width={56}
            />

            <Tooltip content={<AdvisoryTooltip />} cursor={{ fill: 'hsl(var(--muted) / 0.4)' }} />

            {/* Zero line anchors the diverging bars. */}
            <ReferenceLine y={0} stroke="hsl(var(--muted-foreground))" strokeWidth={1} />

            <Bar dataKey="change_amount" radius={[4, 4, 4, 4]} maxBarSize={28}>
              {data.map((point, index) => (
                <Cell
                  key={index}
                  fill={
                    point.change_amount > 0
                      ? 'hsl(var(--price-up))'
                      : point.change_amount < 0
                        ? 'hsl(var(--price-down))'
                        : 'hsl(var(--price-flat))'
                  }
                />
              ))}
            </Bar>
          </BarChart>
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

function AdvisoryTooltip({ active, payload }: TooltipRenderProps<Advisory & { label: string }>) {
  if (!active || !payload?.length) return null;

  const point = payload[0]!.payload;

  return (
    <div className="glass rounded-lg px-3 py-2 shadow-glass">
      <p className="mb-1 text-xs font-medium text-muted-foreground">Week of {point.label}</p>
      <p
        className={`tabular text-sm font-semibold ${
          point.change_amount > 0
            ? 'text-price-up'
            : point.change_amount < 0
              ? 'text-price-down'
              : ''
        }`}
      >
        {point.change_amount > 0 ? '+' : ''}
        {formatCurrency(point.change_amount)}/L
      </p>
      {point.notes ? <p className="mt-1 max-w-48 text-xs text-muted-foreground">{point.notes}</p> : null}
    </div>
  );
}

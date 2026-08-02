'use client';

import {
  Bar,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  ComposedChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCompactCurrency, formatCurrency, formatNumber } from '@/lib/utils';
import type { MonthlyPoint } from '@/types/api';

interface ExpenseChartProps {
  data: MonthlyPoint[];
  loading?: boolean;
  height?: number;
}

/**
 * Monthly spend as bars with average price per litre overlaid as a line.
 *
 * The pairing answers the question users actually have: "did I spend more
 * because prices rose, or because I drove more?" A rising bar with a flat
 * line means the latter.
 */
export function ExpenseChart({ data, loading = false, height = 300 }: ExpenseChartProps) {
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

  const peak = Math.max(...data.map((point) => point.total_cost), 0);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Monthly fuel spend</CardTitle>
        <CardDescription>Spend against the average price you paid per litre</CardDescription>
      </CardHeader>

      <CardContent>
        <ResponsiveContainer width="100%" height={height}>
          <ComposedChart data={data} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" vertical={false} />

            <XAxis
              dataKey="period"
              tickFormatter={(value: string) => {
                const [year, month] = value.split('-');
                return new Date(Number(year), Number(month) - 1).toLocaleDateString('en-PH', {
                  month: 'short',
                });
              }}
              stroke="hsl(var(--muted-foreground))"
              fontSize={11}
              tickLine={false}
              axisLine={false}
            />

            <YAxis
              yAxisId="cost"
              tickFormatter={(value: number) => formatCompactCurrency(value)}
              stroke="hsl(var(--muted-foreground))"
              fontSize={11}
              tickLine={false}
              axisLine={false}
              width={56}
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

            <Tooltip content={<ExpenseTooltip />} cursor={{ fill: 'hsl(var(--muted) / 0.4)' }} />

            <Bar yAxisId="cost" dataKey="total_cost" name="Spend" radius={[6, 6, 0, 0]} maxBarSize={44}>
              {data.map((point) => (
                <Cell
                  key={point.period}
                  // The highest month is highlighted so the outlier is
                  // findable without reading every bar.
                  fill={
                    point.total_cost === peak ? 'hsl(var(--chart-1))' : 'hsl(var(--chart-1) / 0.45)'
                  }
                />
              ))}
            </Bar>

            <Line
              yAxisId="price"
              type="monotone"
              dataKey="avg_price"
              name="Average ₱/L"
              stroke="hsl(var(--chart-3))"
              strokeWidth={2}
              dot={{ r: 3, strokeWidth: 0 }}
            />

            <Legend
              verticalAlign="bottom"
              height={28}
              wrapperStyle={{ fontSize: 11, color: 'hsl(var(--muted-foreground))' }}
            />
          </ComposedChart>
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

function ExpenseTooltip({ active, payload, label }: TooltipRenderProps<MonthlyPoint>) {
  if (!active || !payload?.length) return null;

  const point = payload[0]!.payload;
  const [year, month] = String(label).split('-');

  return (
    <div className="glass rounded-lg px-3 py-2 shadow-glass">
      <p className="mb-1.5 text-xs font-medium text-muted-foreground">
        {new Date(Number(year), Number(month) - 1).toLocaleDateString('en-PH', {
          month: 'long',
          year: 'numeric',
        })}
      </p>
      <div className="space-y-0.5 text-sm">
        <p className="tabular font-semibold">{formatCurrency(point.total_cost)}</p>
        <p className="tabular text-xs text-muted-foreground">
          {formatNumber(point.total_litres, 1)} L over {point.fill_ups} fill-up
          {point.fill_ups === 1 ? '' : 's'}
        </p>
        <p className="tabular text-xs text-muted-foreground">
          {formatCurrency(point.avg_price)}/L average
        </p>
      </div>
    </div>
  );
}

'use client';

import { Fuel } from 'lucide-react';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCurrency } from '@/lib/utils';
import type { PriceComparison } from '@/types/api';

interface PriceComparisonTableProps {
  data: PriceComparison[];
  loading?: boolean;
}

/**
 * Cheapest, average and dearest per fuel type, with the spread called out.
 *
 * The spread column is the actionable one — it is the money a driver can save
 * by choosing a different station today, before any forecast is involved.
 */
export function PriceComparisonTable({ data, loading = false }: PriceComparisonTableProps) {
  if (loading) {
    return (
      <Card>
        <CardHeader>
          <Skeleton className="h-5 w-44" />
        </CardHeader>
        <CardContent className="space-y-3">
          {Array.from({ length: 4 }).map((_, index) => (
            <Skeleton key={index} className="h-12 w-full" />
          ))}
        </CardContent>
      </Card>
    );
  }

  if (!data.length) {
    return (
      <Card>
        <EmptyState
          icon={Fuel}
          title="No price data yet"
          description="Prices appear here once stations in your area start reporting."
        />
      </Card>
    );
  }

  const widestSpread = Math.max(...data.map((row) => row.spread));

  return (
    <Card>
      <CardHeader>
        <CardTitle>Price comparison</CardTitle>
        <CardDescription>
          Live prices across {data[0]?.station_count ?? 0} reporting stations
        </CardDescription>
      </CardHeader>

      <CardContent className="px-0">
        {/* Wide tables scroll inside their own container so the page never
            scrolls horizontally on a phone. */}
        <div className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-sm">
            <thead>
              <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
                <th scope="col" className="px-5 py-2 text-left font-medium">Fuel type</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">Cheapest</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">Average</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">Dearest</th>
                <th scope="col" className="px-5 py-2 text-right font-medium">Spread</th>
              </tr>
            </thead>

            <tbody>
              {data.map((row) => (
                <tr key={row.fuel_type_id} className="border-b border-border/50 last:border-0 hover:bg-muted/40">
                  <th scope="row" className="px-5 py-3 text-left font-medium">
                    {row.fuel_name}
                  </th>
                  <td className="tabular px-3 py-3 text-right font-medium text-price-down">
                    {formatCurrency(row.min_price)}
                  </td>
                  <td className="tabular px-3 py-3 text-right text-muted-foreground">
                    {formatCurrency(row.avg_price)}
                  </td>
                  <td className="tabular px-3 py-3 text-right text-price-up">
                    {formatCurrency(row.max_price)}
                  </td>
                  <td className="px-5 py-3 text-right">
                    <div className="flex items-center justify-end gap-2">
                      <div className="h-1.5 w-12 overflow-hidden rounded-full bg-muted">
                        <div
                          className="h-full rounded-full bg-chart-3"
                          style={{
                            width: `${widestSpread > 0 ? (row.spread / widestSpread) * 100 : 0}%`,
                          }}
                        />
                      </div>
                      <span className="tabular font-medium">{formatCurrency(row.spread)}</span>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  );
}

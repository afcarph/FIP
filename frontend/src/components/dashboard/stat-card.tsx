'use client';

import { ArrowDownRight, ArrowUpRight, Minus, type LucideIcon } from 'lucide-react';

import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface StatCardProps {
  label: string;
  value: string;
  icon?: LucideIcon;
  /** Signed percentage change against the comparison period. */
  change?: number | null;
  changeLabel?: string;
  /**
   * Whether an increase is good news. Fuel spend rising is bad; savings
   * rising is good — the same arrow must not always be the same colour.
   */
  higherIsBetter?: boolean;
  hint?: string;
  loading?: boolean;
  accent?: 'primary' | 'success' | 'warning' | 'danger';
}

const accentClasses = {
  primary: 'bg-primary/10 text-primary',
  success: 'bg-price-down/10 text-price-down',
  warning: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  danger: 'bg-destructive/10 text-destructive',
};

export function StatCard({
  label,
  value,
  icon: Icon,
  change,
  changeLabel = 'vs. last period',
  higherIsBetter = false,
  hint,
  loading = false,
  accent = 'primary',
}: StatCardProps) {
  if (loading) {
    return (
      <Card className="p-5">
        <Skeleton className="mb-3 h-3 w-20" />
        <Skeleton className="mb-2 h-8 w-28" />
        <Skeleton className="h-3 w-24" />
      </Card>
    );
  }

  const hasChange = change !== null && change !== undefined && Number.isFinite(change);
  const isPositive = hasChange && change > 0.05;
  const isNegative = hasChange && change < -0.05;
  const isGood = isPositive ? higherIsBetter : isNegative ? !higherIsBetter : null;

  return (
    <Card className="group relative overflow-hidden p-5 transition-shadow hover:shadow-glass">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {label}
          </p>
          <p className="tabular mt-1.5 text-2xl font-semibold tracking-tight">{value}</p>

          {hasChange ? (
            <div className="mt-2 flex items-center gap-1.5">
              <span
                className={cn(
                  'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs font-medium',
                  isGood === true && 'bg-price-down/10 text-price-down',
                  isGood === false && 'bg-price-up/10 text-price-up',
                  isGood === null && 'bg-muted text-muted-foreground',
                )}
              >
                {isPositive ? (
                  <ArrowUpRight className="size-3" aria-hidden="true" />
                ) : isNegative ? (
                  <ArrowDownRight className="size-3" aria-hidden="true" />
                ) : (
                  <Minus className="size-3" aria-hidden="true" />
                )}
                <span className="tabular">
                  {change > 0 ? '+' : ''}
                  {change.toFixed(1)}%
                </span>
              </span>
              <span className="truncate text-xs text-muted-foreground">{changeLabel}</span>
            </div>
          ) : hint ? (
            <p className="mt-2 truncate text-xs text-muted-foreground">{hint}</p>
          ) : null}
        </div>

        {Icon ? (
          <div className={cn('shrink-0 rounded-lg p-2.5', accentClasses[accent])}>
            <Icon className="size-5" aria-hidden="true" />
          </div>
        ) : null}
      </div>
    </Card>
  );
}

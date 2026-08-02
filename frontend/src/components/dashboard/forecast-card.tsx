'use client';

import { motion } from 'framer-motion';
import { Info, Minus, TrendingDown, TrendingUp } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import type { ForecastDriver, PriceDirection } from '@/types/api';

interface ForecastCardProps {
  fuelType: string;
  direction: PriceDirection;
  changeAmount: number;
  confidence: number;
  narrative?: string | null;
  effectiveWeek?: string;
  drivers?: ForecastDriver[];
}

/**
 * The forecast card is the platform's headline claim, so it shows its
 * working: the direction, the size, how confident the model is, and the
 * ranked factors behind it. A prediction the user cannot interrogate is one
 * they have no reason to trust.
 */
export function ForecastCard({
  fuelType,
  direction,
  changeAmount,
  confidence,
  narrative,
  effectiveWeek,
  drivers = [],
}: ForecastCardProps) {
  const [showDrivers, setShowDrivers] = React.useState(false);

  const config = {
    increase: {
      Icon: TrendingUp,
      badge: 'up' as const,
      tint: 'text-price-up',
      surface: 'from-price-up/10',
      verb: 'Increase expected',
    },
    rollback: {
      Icon: TrendingDown,
      badge: 'down' as const,
      tint: 'text-price-down',
      surface: 'from-price-down/10',
      verb: 'Rollback expected',
    },
    no_change: {
      Icon: Minus,
      badge: 'flat' as const,
      tint: 'text-muted-foreground',
      surface: 'from-muted',
      verb: 'No change expected',
    },
  }[direction];

  const confidencePct = Math.round(confidence * 100);
  const confidenceLabel = confidence >= 0.85 ? 'High' : confidence >= 0.65 ? 'Moderate' : 'Low';

  return (
    <Card className="relative overflow-hidden">
      <div
        className={cn('pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b to-transparent', config.surface)}
        aria-hidden="true"
      />

      <CardHeader className="relative flex-row items-start justify-between space-y-0">
        <div>
          <CardTitle className="text-base">{fuelType}</CardTitle>
          {effectiveWeek ? (
            <CardDescription>Effective {formatDate(effectiveWeek)}</CardDescription>
          ) : null}
        </div>

        <Badge variant={config.badge}>
          <config.Icon className="size-3" aria-hidden="true" />
          {confidenceLabel} confidence
        </Badge>
      </CardHeader>

      <CardContent className="relative space-y-4">
        <div>
          <div className="flex items-baseline gap-2">
            <span className={cn('tabular text-3xl font-bold tracking-tight', config.tint)}>
              {direction === 'no_change'
                ? '—'
                : `${changeAmount > 0 ? '+' : ''}${formatCurrency(changeAmount)}`}
            </span>
            {direction !== 'no_change' ? (
              <span className="text-sm text-muted-foreground">per litre</span>
            ) : null}
          </div>
          <p className="mt-0.5 text-sm font-medium">{config.verb}</p>
        </div>

        {/* Confidence as a bar: a number alone reads as precision it does
            not have, a bar reads as a level. */}
        <div>
          <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
            <span>Model confidence</span>
            <span className="tabular font-medium">{confidencePct}%</span>
          </div>
          <div
            className="h-1.5 overflow-hidden rounded-full bg-muted"
            role="meter"
            aria-valuenow={confidencePct}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label="Model confidence"
          >
            <motion.div
              className={cn(
                'h-full rounded-full',
                confidence >= 0.85
                  ? 'bg-price-down'
                  : confidence >= 0.65
                    ? 'bg-primary'
                    : 'bg-amber-500',
              )}
              initial={{ width: 0 }}
              animate={{ width: `${confidencePct}%` }}
              transition={{ duration: 0.6, ease: 'easeOut' }}
            />
          </div>
        </div>

        {narrative ? <p className="text-sm leading-relaxed text-muted-foreground">{narrative}</p> : null}

        {drivers.length > 0 ? (
          <div>
            <button
              type="button"
              onClick={() => setShowDrivers((open) => !open)}
              className="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
              aria-expanded={showDrivers}
            >
              <Info className="size-3.5" aria-hidden="true" />
              {showDrivers ? 'Hide' : 'Why this forecast?'}
            </button>

            {showDrivers ? (
              <motion.ul
                className="mt-3 space-y-2"
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: 'auto' }}
                transition={{ duration: 0.2 }}
              >
                {drivers.map((driver) => (
                  <li key={driver.factor} className="flex items-center gap-2 text-xs">
                    <div className="h-1.5 w-16 shrink-0 overflow-hidden rounded-full bg-muted">
                      <div
                        className="h-full rounded-full bg-primary"
                        style={{ width: `${Math.round(driver.weight * 100)}%` }}
                      />
                    </div>
                    <span className="flex-1 truncate text-muted-foreground">{driver.factor}</span>
                    <span className="tabular shrink-0 font-medium">{driver.value}</span>
                  </li>
                ))}
              </motion.ul>
            ) : null}
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}

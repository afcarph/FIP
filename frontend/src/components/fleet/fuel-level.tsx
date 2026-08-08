'use client';

import { AlertTriangle, Fuel, HelpCircle } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { cn, formatLitres, formatRelative } from '@/lib/utils';
import type { FuelStatus } from '@/types/api';

/**
 * How a tank level is presented across the fleet screens.
 *
 * The rule these components exist to enforce: an unknown level is never drawn
 * as a healthy one. The API returns `status: null` for a vehicle nobody has
 * reported on, and rendering that as a green NORMAL badge would invent a
 * reassurance the data does not support — on a fuel-theft product, that is the
 * single most expensive thing the UI could get wrong.
 */

const STATUS_STYLES: Record<FuelStatus, { variant: 'success' | 'warning' | 'destructive'; label: string }> = {
  NORMAL: { variant: 'success', label: 'Normal' },
  LOW: { variant: 'warning', label: 'Low' },
  CRITICAL: { variant: 'destructive', label: 'Critical' },
};

export function FuelStatusBadge({
  status,
  isStale = false,
  className,
}: {
  status: FuelStatus | null;
  isStale?: boolean;
  className?: string;
}) {
  if (status === null) {
    return (
      <Badge variant="flat" className={className}>
        <HelpCircle className="size-3" aria-hidden="true" />
        No reading
      </Badge>
    );
  }

  const { variant, label } = STATUS_STYLES[status];

  return (
    <span className={cn('inline-flex items-center gap-1.5', className)}>
      <Badge variant={variant}>
        {status === 'CRITICAL' ? <AlertTriangle className="size-3" aria-hidden="true" /> : null}
        {label}
      </Badge>
      {/* A stale level is still a real measurement, so it keeps its band and
          gains a qualifier rather than being hidden or downgraded. */}
      {isStale ? (
        <Badge variant="outline" title="This reading is older than the freshness window">
          Stale
        </Badge>
      ) : null}
    </span>
  );
}

/**
 * A level bar with the percentage beside it.
 *
 * Colour tracks the same three bands, but the number is always shown: the bar
 * is the glanceable layer and the figure is the one people act on.
 */
export function FuelLevelBar({
  percentage,
  litres,
  status,
  recordedAt,
  className,
}: {
  percentage: number | null;
  litres?: number | null;
  status: FuelStatus | null;
  recordedAt?: string | null;
  className?: string;
}) {
  if (percentage === null) {
    return <span className={cn('text-sm text-muted-foreground', className)}>—</span>;
  }

  const fill =
    status === 'CRITICAL'
      ? 'bg-destructive'
      : status === 'LOW'
        ? 'bg-amber-500'
        : 'bg-price-down';

  return (
    <div className={cn('min-w-[7.5rem] space-y-1', className)}>
      <div className="flex items-baseline justify-between gap-2">
        <span className="tabular text-sm font-medium">{percentage.toFixed(1)}%</span>
        {litres != null ? (
          <span className="tabular text-xs text-muted-foreground">{formatLitres(litres)}</span>
        ) : null}
      </div>

      <div
        className="h-1.5 w-full overflow-hidden rounded-full bg-muted"
        role="img"
        aria-label={`Tank at ${percentage.toFixed(1)} percent`}
      >
        <div
          className={cn('h-full rounded-full transition-[width]', fill)}
          style={{ width: `${Math.min(100, Math.max(0, percentage))}%` }}
        />
      </div>

      {recordedAt ? (
        <p className="text-xs text-muted-foreground">{formatRelative(recordedAt)}</p>
      ) : null}
    </div>
  );
}

/** Empty-state icon for fuel surfaces, kept here so the screens agree. */
export const FuelIcon = Fuel;

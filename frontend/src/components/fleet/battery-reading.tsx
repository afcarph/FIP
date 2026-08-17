'use client';

import { Battery, BatteryFull, BatteryLow, BatteryMedium, Plug } from 'lucide-react';
import * as React from 'react';

import { formatRelative } from '@/lib/utils';
import type { DeviceHealth } from '@/types/api';

/**
 * A battery reading, shown only as confidently as it deserves.
 *
 * The reason this is a component rather than a line of JSX in two pages: a
 * device that stopped reporting still holds the last percentage it sent, and
 * rendering that number plainly makes yesterday's charge look like today's.
 * Somebody then goes looking for a van whose phone is simply flat.
 *
 * So a stale reading is greyed and dated rather than hidden. Hiding it would
 * lose the most useful thing anyone can say about a device that has gone quiet
 * — that it was on 4% when it stopped — which is usually the whole answer.
 */
export function BatteryReading({
  battery,
  showTimestamp = false,
}: {
  battery: DeviceHealth['battery'];
  showTimestamp?: boolean;
}) {
  if (battery.percentage === null) {
    return <span className="text-sm text-muted-foreground">Not reported</span>;
  }

  const Icon = battery.is_charging
    ? Plug
    : battery.percentage >= 80
      ? BatteryFull
      : battery.percentage >= 30
        ? BatteryMedium
        : battery.percentage > 0
          ? BatteryLow
          : Battery;

  // Colour states a fact rather than a mood: red only when the reading is both
  // current and genuinely low, because a stale 4% is history and a charging 4%
  // is already being dealt with.
  const tone = !battery.is_fresh
    ? 'text-muted-foreground'
    : battery.is_low
      ? 'text-destructive'
      : battery.is_charging
        ? 'text-green-600 dark:text-green-500'
        : 'text-foreground';

  return (
    <div className={`flex items-center gap-1.5 ${tone}`}>
      <Icon className="h-4 w-4 shrink-0" aria-hidden />
      <span className="text-sm tabular-nums">{battery.percentage}%</span>

      {!battery.is_fresh && battery.updated_at && (
        <span className="text-xs" title={`Last reported ${formatRelative(battery.updated_at)}`}>
          ({formatRelative(battery.updated_at)})
        </span>
      )}

      {battery.is_fresh && showTimestamp && battery.updated_at && (
        <span className="text-xs text-muted-foreground">
          {formatRelative(battery.updated_at)}
        </span>
      )}
    </div>
  );
}

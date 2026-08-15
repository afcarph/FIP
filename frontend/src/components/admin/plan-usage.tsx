'use client';

import * as React from 'react';

import type { SubscriptionReport } from '@/types/api';

const LABELS: Record<string, string> = {
  vehicles: 'Vehicles',
  seats: 'User seats',
  devices: 'Tracked devices',
};

/**
 * What a tenant has used against what its plan allows.
 *
 * One rule matters more than the layout: a null limit means *unlimited*, not
 * zero. Rendering it as a number would show an enterprise tenant as
 * permanently full, and a progress bar would sit pinned at 100% forever — so
 * unlimited resources get no bar at all, because there is no proportion to
 * draw.
 *
 * `over_limit` is shown rather than hidden. A tenant moved to a smaller plan
 * keeps everything it already has; the limits only refuse new records. Saying
 * "6 of 3" out loud is the honest description of that state, and hiding it
 * would leave an operator wondering why a create was refused.
 */
export function PlanUsage({ report }: { report: SubscriptionReport }) {
  return (
    <div className="space-y-4">
      {(Object.keys(LABELS) as Array<keyof SubscriptionReport['resources']>).map((key) => {
        const resource = report.resources[key];
        if (!resource) return null;

        // Narrowed via the limit itself rather than a boolean, so TypeScript
        // can see that the division below never touches null.
        const limit = resource.limit;
        const pct =
          limit === null ? 0 : Math.min(100, Math.round((resource.used / Math.max(1, limit)) * 100));

        return (
          <div key={key} className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-4">
              <span className="text-sm">{LABELS[key]}</span>
              <span
                className={
                  resource.over_limit
                    ? 'text-sm font-medium tabular-nums text-destructive'
                    : 'text-sm tabular-nums text-muted-foreground'
                }
              >
                {resource.used} of {limit === null ? 'unlimited' : limit}
              </span>
            </div>

            {limit !== null && (
              <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                  className={
                    resource.over_limit || resource.remaining === 0
                      ? 'h-full rounded-full bg-destructive'
                      : 'h-full rounded-full bg-primary'
                  }
                  style={{ width: `${pct}%` }}
                />
              </div>
            )}

            {resource.over_limit && (
              <p className="text-xs text-muted-foreground">
                Above the plan. Nothing has been removed — only new ones are refused.
              </p>
            )}
          </div>
        );
      })}

      {report.is_provisional && (
        <p className="text-xs text-muted-foreground">
          These figures are provisional and awaiting business approval.
        </p>
      )}
    </div>
  );
}

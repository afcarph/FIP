'use client';

import { Car, CheckCircle2, Route, Wrench } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate, formatRelative } from '@/lib/utils';
import type { FleetOverview as FleetOverviewData } from '@/types/api';

/**
 * How an operational state reads and colours.
 *
 * Semantic only — green for ready, amber for attention, red for stopped. These
 * are deliberately not the FIP brand green: a vehicle being available and a
 * button being branded are different meanings, and letting them share a colour
 * makes the status column decorative instead of informative.
 */
const STATE: Record<
  FleetOverviewData['vehicles'][number]['state'],
  { label: string; variant: 'success' | 'warning' | 'destructive' | 'secondary' }
> = {
  available: { label: 'Available', variant: 'success' },
  on_trip: { label: 'On trip', variant: 'warning' },
  maintenance: { label: 'Maintenance', variant: 'destructive' },
  inactive: { label: 'Inactive', variant: 'secondary' },
};

function SummaryTile({
  icon: Icon,
  label,
  value,
  tone,
}: {
  icon: typeof Car;
  label: string;
  value: number;
  tone?: 'ok' | 'warn' | 'bad';
}) {
  const toneClass =
    tone === 'ok'
      ? 'text-emerald-600 dark:text-emerald-400'
      : tone === 'warn'
        ? 'text-amber-600 dark:text-amber-400'
        : tone === 'bad'
          ? 'text-destructive'
          : 'text-foreground';

  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <Icon className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />
        <div className="min-w-0">
          <p className={`text-2xl font-semibold tabular-nums leading-none ${toneClass}`}>{value}</p>
          <p className="mt-1 truncate text-xs text-muted-foreground">{label}</p>
        </div>
      </CardContent>
    </Card>
  );
}

/**
 * The fleet-first block: what is happening right now, before any analysis.
 *
 * Every panel renders an honest empty state rather than a placeholder figure.
 * A fleet with no vehicles reads as zero, not as a demo.
 */
export function FleetOverview({ data }: { data: FleetOverviewData }) {
  const { summary, vehicles, maintenance, recent_activity: activity } = data;

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryTile icon={Car} label="Vehicles" value={summary.total_vehicles} />
        <SummaryTile icon={CheckCircle2} label="Available" value={summary.available} tone="ok" />
        <SummaryTile icon={Route} label="On trip" value={summary.on_trip} tone="warn" />
        <SummaryTile
          icon={Wrench}
          label="In maintenance"
          value={summary.maintenance}
          tone={summary.maintenance > 0 ? 'bad' : undefined}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Fleet status</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            {vehicles.length === 0 ? (
              <p className="px-6 pb-6 text-sm text-muted-foreground">
                No vehicles yet. Add one to see it here.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="px-6 py-2 text-left font-medium">Vehicle</th>
                      <th className="px-3 py-2 text-left font-medium">Driver</th>
                      <th className="px-6 py-2 text-right font-medium">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {vehicles.map((vehicle) => (
                      <tr key={vehicle.id} className="border-b border-border last:border-0">
                        <td className="px-6 py-2.5">
                          <span className="font-medium">{vehicle.plate_number}</span>
                          {vehicle.display_name ? (
                            <span className="block text-xs text-muted-foreground">
                              {vehicle.display_name}
                            </span>
                          ) : null}
                        </td>
                        <td className="px-3 py-2.5 text-muted-foreground">
                          {vehicle.driver?.name ?? 'Unassigned'}
                        </td>
                        <td className="px-6 py-2.5 text-right">
                          <Badge variant={STATE[vehicle.state].variant}>
                            {STATE[vehicle.state].label}
                          </Badge>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-base">Needs attention</CardTitle>
            </CardHeader>
            <CardContent>
              {maintenance.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  Nothing overdue or due soon.
                </p>
              ) : (
                <ul className="space-y-2.5">
                  {maintenance.map((item) => (
                    <li key={item.id} className="flex items-start justify-between gap-3 text-sm">
                      <span>
                        <span className="font-medium">{item.vehicle ?? 'Unknown vehicle'}</span>
                        <span className="block text-xs text-muted-foreground">
                          {item.service ?? 'Scheduled service'}
                        </span>
                      </span>
                      <span className="shrink-0 text-right">
                        <Badge variant={item.status === 'overdue' ? 'destructive' : 'warning'}>
                          {item.status === 'overdue' ? 'Overdue' : 'Due soon'}
                        </Badge>
                        {item.due_at ? (
                          <span className="mt-1 block text-xs text-muted-foreground">
                            {formatDate(item.due_at)}
                          </span>
                        ) : null}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-base">Recent activity</CardTitle>
            </CardHeader>
            <CardContent>
              {activity.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  No fleet activity recorded yet.
                </p>
              ) : (
                <ul className="space-y-2.5">
                  {activity.map((event, index) => (
                    <li key={`${event.type}-${index}`} className="flex items-baseline gap-3 text-sm">
                      <span className="w-20 shrink-0 text-xs tabular-nums text-muted-foreground">
                        {formatRelative(event.at)}
                      </span>
                      <span className="min-w-0">{event.summary}</span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}

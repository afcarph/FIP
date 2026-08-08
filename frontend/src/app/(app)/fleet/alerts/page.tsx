'use client';

import {
  ArrowLeft,
  Check,
  Fuel,
  Search,
  ShieldAlert,
  TriangleAlert,
  X,
} from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useFleetAlerts, useResolveAlert } from '@/hooks/use-api';
import { formatDateTime, formatLitres, formatNumber, formatRelative } from '@/lib/utils';
import type { AlertSignal, FleetAlert } from '@/types/api';

const STATUSES = [
  { value: '', label: 'Needs review' },
  { value: 'investigating', label: 'Investigating' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'dismissed', label: 'Dismissed' },
] as const;

const SEVERITIES = [
  { value: '', label: 'Any severity' },
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
] as const;

/**
 * How each rule's measurements read in a sentence.
 *
 * The evidence blob is shaped per rule, so it is rendered per rule. Dumping the
 * raw JSON would technically show the same numbers, but the point of an alert
 * queue is that somebody can judge it in a few seconds — and "41.0 points in
 * 1.2 min" is a judgement, where a key-value dump is homework.
 */
function describeSignal(signal: AlertSignal): string {
  const d = signal.detail;

  switch (signal.type) {
    case 'abnormal_fuel_drop':
      return [
        `Fell from ${d.from_pct}% to ${d.to_pct}%`,
        `${d.dropped_pct} points in ${d.over_minutes} min`,
        d.litres_lost != null ? `about ${formatLitres(Number(d.litres_lost))}` : null,
      ]
        .filter(Boolean)
        .join(' · ');

    case 'unexplained_fuel_gain':
      return `Rose from ${d.from_pct}% to ${d.to_pct}% (+${d.gained_pct} points) with no fill-up recorded`;

    case 'sensor_anomaly':
      return `Fell ${d.fell_pct} points then rose ${d.rose_pct} with nothing bought between`;

    case 'overfill':
      return `${d.litres} L into a ${d.tank_capacity} L tank — ${d.excess_litres} L more than it holds`;

    case 'ghost_refuel':
    case 'excess_consumption':
      return `${d.km_per_litre} km/L against a ${d.baseline_km_per_litre} km/L baseline (${d.deviation_pct}% off)`;

    case 'rapid_refuel':
      return `Second fill-up only ${d.minutes_since_previous} min after the last`;

    case 'odometer_rollback':
      return `Odometer went backwards: ${d.previous} → ${d.current}`;

    case 'price_mismatch':
      return `Paid ${d.claimed_price} where the station published ${d.published_price}`;

    case 'location_mismatch':
      return `Recorded ${d.distance_km} km from the station it names`;

    default:
      // An unknown rule still has to render something honest rather than
      // nothing — new detectors should not silently show a blank card.
      return Object.entries(d)
        .map(([key, value]) => `${key.replace(/_/g, ' ')}: ${value}`)
        .join(' · ');
  }
}

const SEVERITY_VARIANT = {
  critical: 'destructive',
  high: 'destructive',
  medium: 'warning',
  low: 'secondary',
} as const;

function FleetAlertsBody() {
  const [status, setStatus] = React.useState('');
  const [severity, setSeverity] = React.useState('');
  const [search, setSearch] = React.useState('');

  const filters = React.useMemo(
    () => ({
      ...(status ? { status } : {}),
      ...(severity ? { severity } : {}),
      per_page: 100,
    }),
    [status, severity],
  );

  const { data: alerts, isLoading } = useFleetAlerts(filters);

  // Plate filtering is client-side because the API does not offer it and the
  // page already holds the rows; adding a server filter for this would be a
  // backend change the queue does not need.
  const rows = (alerts ?? []).filter((alert) => {
    if (!search) return true;
    const needle = search.toLowerCase();
    return (
      alert.vehicle?.plate_number.toLowerCase().includes(needle) ||
      `${alert.driver?.first_name ?? ''} ${alert.driver?.last_name ?? ''}`
        .toLowerCase()
        .includes(needle)
    );
  });

  return (
    <div className="space-y-6">
      <header className="space-y-3">
        <Link
          href="/fleet"
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
          Fleet operations
        </Link>

        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Fuel anomalies</h1>
          <p className="text-sm text-muted-foreground">
            Movements the vehicle&apos;s own history does not explain. A flag is a question,
            not a verdict — the cause could be siphoning, a leak, a sensor fault or
            maintenance.
          </p>
        </div>
      </header>

      <Card>
        <CardContent className="space-y-4 pt-6">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div className="relative flex-1">
              <Search
                className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
              />
              <Input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Filter by plate or driver"
                aria-label="Filter alerts"
                className="pl-9"
              />
            </div>

            <div className="flex items-center gap-2">
              <label className="sr-only" htmlFor="status">Status</label>
              <select
                id="status"
                value={status}
                onChange={(event) => setStatus(event.target.value)}
                className="h-9 rounded-lg border border-input bg-background px-3 text-sm"
              >
                {STATUSES.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>

              <label className="sr-only" htmlFor="severity">Severity</label>
              <select
                id="severity"
                value={severity}
                onChange={(event) => setSeverity(event.target.value)}
                className="h-9 rounded-lg border border-input bg-background px-3 text-sm"
              >
                {SEVERITIES.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </div>
          </div>

          {isLoading ? (
            <div className="space-y-3">
              {[0, 1, 2].map((key) => (
                <Skeleton key={key} className="h-28 w-full" />
              ))}
            </div>
          ) : rows.length === 0 ? (
            <EmptyState
              icon={ShieldAlert}
              title={status || severity || search ? 'Nothing matches those filters' : 'No open anomalies'}
              description={
                status || severity || search
                  ? 'Try a different status, severity or plate.'
                  : 'Nothing in recent fuel activity looks unexplained.'
              }
              className="py-10"
            />
          ) : (
            <ul className="space-y-3">
              {rows.map((alert) => (
                <li key={alert.id}>
                  <AlertCard alert={alert} />
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function AlertCard({ alert }: { alert: FleetAlert }) {
  const resolve = useResolveAlert();
  const [note, setNote] = React.useState('');
  const [open, setOpen] = React.useState(false);

  const signals = alert.evidence?.signals ?? [];
  const isSimulated = alert.evidence?.source === 'simulated' || alert.reading?.source === 'simulated';
  const settled = alert.status === 'confirmed' || alert.status === 'dismissed';

  const act = (status: 'investigating' | 'confirmed' | 'dismissed') => {
    resolve.mutate({ id: alert.id, status, note: note || undefined });
    setOpen(false);
  };

  return (
    <div className="rounded-xl border p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={SEVERITY_VARIANT[alert.severity]}>
              {alert.severity === 'critical' || alert.severity === 'high' ? (
                <TriangleAlert className="size-3" aria-hidden="true" />
              ) : null}
              {alert.severity}
            </Badge>

            <span className="font-medium capitalize">
              {alert.alert_type.replace(/_/g, ' ')}
            </span>

            <span className="tabular text-xs text-muted-foreground">
              score {formatNumber(alert.score, 2)}
            </span>

            {/* Simulated alerts are separable in the database; they must be
                separable here too, or a demo becomes an incident. */}
            {isSimulated ? <Badge variant="secondary">simulated</Badge> : null}

            {settled ? (
              <Badge variant="outline" className="capitalize">{alert.status}</Badge>
            ) : null}
          </div>

          <p className="text-sm text-muted-foreground">
            {alert.vehicle ? (
              <Link href={`/vehicles/${alert.vehicle.id}`} className="hover:text-primary hover:underline">
                {alert.vehicle.plate_number}
              </Link>
            ) : (
              'Unknown vehicle'
            )}
            {alert.driver ? ` · ${alert.driver.first_name} ${alert.driver.last_name}` : ''}
            {' · '}
            <span title={formatDateTime(alert.detected_at)}>{formatRelative(alert.detected_at)}</span>
          </p>
        </div>

        {!settled ? (
          <div className="flex flex-wrap items-center gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() => setOpen((value) => !value)}
              disabled={resolve.isPending}
            >
              Resolve
            </Button>
          </div>
        ) : null}
      </div>

      <ul className="mt-3 space-y-1">
        {signals.map((signal, index) => (
          <li key={`${signal.type}-${index}`} className="flex items-start gap-2 text-sm">
            <Fuel className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span>{describeSignal(signal)}</span>
          </li>
        ))}
      </ul>

      {alert.resolution_note ? (
        <p className="mt-2 text-xs text-muted-foreground">
          Note: {alert.resolution_note}
        </p>
      ) : null}

      {open && !settled ? (
        <div className="mt-3 space-y-2 border-t pt-3">
          <Input
            value={note}
            onChange={(event) => setNote(event.target.value)}
            placeholder="What did you find? (optional)"
            aria-label="Resolution note"
          />

          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" onClick={() => act('investigating')}>
              Investigating
            </Button>
            {/* "Confirmed" means the anomaly was real, not that anyone stole
                anything — the wording stays about the measurement. */}
            <Button size="sm" variant="destructive" onClick={() => act('confirmed')}>
              <Check aria-hidden="true" />
              Confirm anomaly
            </Button>
            <Button size="sm" variant="ghost" onClick={() => act('dismissed')}>
              <X aria-hidden="true" />
              Dismiss
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

export default function FleetAlertsPage() {
  return (
    // The same roles that can open /fleet, so the nav and the route agree.
    <RequireRole roles={['fleet_manager', 'company_manager', 'super_admin', 'system_admin']}>
      <FleetAlertsBody />
    </RequireRole>
  );
}

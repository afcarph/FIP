'use client';

import { Fuel, MapPin, Navigation, SlidersHorizontal } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { StationMap } from '@/components/map/station-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useFuelTypes, useNearbyStations } from '@/hooks/use-api';
import { useGeolocation } from '@/hooks/use-geolocation';
import { cn, formatCurrency, formatDistance, formatRelative } from '@/lib/utils';

const RADII = [2, 5, 10, 25];

export default function MapPage() {
  const {
    latitude,
    longitude,
    usingFallback,
    loading: locating,
    request,
    isBlocked,
  } = useGeolocation({ immediate: false });
  const { data: fuelTypes } = useFuelTypes();

  const [fuelTypeId, setFuelTypeId] = React.useState<number | undefined>();
  const [radius, setRadius] = React.useState(5);

  const { data: stations, isLoading } = useNearbyStations(latitude, longitude, radius, fuelTypeId);

  // Cheapest first — the reason most people open this screen.
  const ranked = React.useMemo(() => {
    if (!stations) return [];

    return [...stations].sort((a, b) => {
      const priceA = priceOf(a, fuelTypeId);
      const priceB = priceOf(b, fuelTypeId);

      if (priceA === null) return 1;
      if (priceB === null) return -1;

      return priceA - priceB;
    });
  }, [stations, fuelTypeId]);

  const best = ranked.find((station) => priceOf(station, fuelTypeId) !== null);
  const bestPrice = best ? priceOf(best, fuelTypeId) : null;

  return (
    <div className="space-y-4">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Fuel near you</h1>
          <p className="text-sm text-muted-foreground">
            {!usingFallback
              ? `Live prices within ${radius} km`
              : isBlocked
                ? 'Showing prices around Makati — this site is blocked from using your location'
                : 'Showing prices around Makati — enable location for results near you'}
          </p>
        </div>

        {/* A blocked permission cannot be re-requested from script — the
            browser refuses without prompting. Offering the button anyway is
            what made this look broken: it fails in about two milliseconds and
            nothing on screen changes. */}
        {usingFallback && !isBlocked ? (
          <Button variant="outline" size="sm" onClick={request} loading={locating}>
            <Navigation aria-hidden="true" />
            Use my location
          </Button>
        ) : null}
      </header>

      {isBlocked ? (
        <Card>
          <CardContent className="flex items-start gap-3 p-4 text-sm">
            <Navigation className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <div>
              <p className="font-medium">Location is blocked for this site</p>
              <p className="text-muted-foreground">
                Your browser has been told to refuse location for{' '}
                <span className="font-mono">fip.nelleeph.com</span>, and it will not ask again
                until you change that. Open the padlock in the address bar, set Location to
                Allow, then reload. Until then everything below is centred on Makati.
              </p>
            </div>
          </CardContent>
        </Card>
      ) : null}

      {/* Filters */}
      <Card>
        <CardContent className="flex flex-wrap items-center gap-4 p-4">
          <div className="flex items-center gap-2">
            <SlidersHorizontal className="size-4 text-muted-foreground" aria-hidden="true" />
            <span className="text-sm font-medium">Fuel</span>
          </div>

          <div className="flex flex-wrap gap-1.5" role="group" aria-label="Fuel type filter">
            <FilterChip active={fuelTypeId === undefined} onClick={() => setFuelTypeId(undefined)}>
              All
            </FilterChip>
            {fuelTypes
              ?.filter((fuel) => ['gasoline', 'diesel'].includes(fuel.category))
              .map((fuel) => (
                <FilterChip
                  key={fuel.id}
                  active={fuelTypeId === fuel.id}
                  onClick={() => setFuelTypeId(fuel.id)}
                >
                  {fuel.name}
                </FilterChip>
              ))}
          </div>

          <div className="ml-auto flex items-center gap-1.5" role="group" aria-label="Search radius">
            <span className="text-sm text-muted-foreground">Radius</span>
            {RADII.map((value) => (
              <FilterChip key={value} active={radius === value} onClick={() => setRadius(value)}>
                {value} km
              </FilterChip>
            ))}
          </div>
        </CardContent>
      </Card>

      {best && bestPrice !== null ? (
        <Card glass className="border-price-down/30">
          <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
            <div className="flex items-center gap-3">
              <div className="rounded-lg bg-price-down/10 p-2.5 text-price-down">
                <Fuel className="size-5" aria-hidden="true" />
              </div>
              <div>
                <p className="text-sm font-medium">
                  Cheapest nearby: {best.name} at{' '}
                  <span className="tabular text-price-down">{formatCurrency(bestPrice)}</span>/L
                </p>
                <p className="text-xs text-muted-foreground">
                  {best.brand?.name}
                  {best.distance_km !== undefined ? ` · ${formatDistance(best.distance_km)} away` : ''}
                </p>
              </div>
            </div>

            <Button asChild size="sm">
              <a
                href={`https://www.google.com/maps/dir/?api=1&destination=${best.latitude},${best.longitude}`}
                target="_blank"
                rel="noopener noreferrer"
              >
                <Navigation aria-hidden="true" />
                Directions
              </a>
            </Button>
          </CardContent>
        </Card>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-5">
        <div className="lg:col-span-3">
          <StationMap
            stations={ranked}
            // Only once the user has actually shared a position. Passing the
            // Makati fallback would drop a "you are here" dot on a place they
            // have never been.
            centre={usingFallback ? null : { latitude, longitude }}
            onUseMyLocation={request}
            locating={locating}
            className="h-[520px]"
          />
        </div>

        {/* Ranked list — the map is for orientation, the list is for deciding. */}
        <div className="lg:col-span-2">
          <Card className="flex h-[520px] flex-col">
            <div className="border-b px-4 py-3">
              <h2 className="text-sm font-semibold">
                {isLoading ? 'Searching…' : `${ranked.length} stations, cheapest first`}
              </h2>
            </div>

            <div className="scrollbar-thin flex-1 overflow-y-auto">
              {isLoading ? (
                <div className="space-y-3 p-4">
                  {Array.from({ length: 6 }).map((_, index) => (
                    <Skeleton key={index} className="h-16 w-full" />
                  ))}
                </div>
              ) : ranked.length ? (
                <ul className="divide-y">
                  {ranked.map((station) => {
                    const price = priceOf(station, fuelTypeId);
                    const priceRow = (station.prices ?? []).find(
                      (row) => !fuelTypeId || row.fuel_type_id === fuelTypeId,
                    );

                    return (
                      <li key={station.id}>
                        <Link
                          href={`/stations/${station.slug}`}
                          className="flex items-start justify-between gap-3 p-4 transition-colors hover:bg-muted/50"
                        >
                          <div className="min-w-0">
                            <p className="truncate text-sm font-medium">{station.name}</p>
                            <p className="truncate text-xs text-muted-foreground">
                              {station.brand?.name}
                              {station.distance_km !== undefined
                                ? ` · ${formatDistance(station.distance_km)}`
                                : ''}
                            </p>

                            <div className="mt-1.5 flex flex-wrap gap-1">
                              {station.is_24_hours ? <Badge variant="secondary">24h</Badge> : null}
                              {station.has_ev_charging ? <Badge variant="secondary">EV</Badge> : null}
                              {priceRow?.is_stale ? <Badge variant="warning">Stale</Badge> : null}
                              {priceRow?.source === 'crowd' ? (
                                <Badge variant="outline">Community</Badge>
                              ) : null}
                            </div>
                          </div>

                          <div className="shrink-0 text-right">
                            <p className="tabular text-base font-semibold">
                              {price !== null ? formatCurrency(price) : '—'}
                            </p>
                            {priceRow ? (
                              <p className="text-xs text-muted-foreground">
                                {formatRelative(priceRow.effective_at)}
                              </p>
                            ) : null}
                          </div>
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              ) : (
                <EmptyState
                  icon={MapPin}
                  title="No stations in range"
                  description="Try widening the radius or clearing the fuel type filter."
                  action={
                    <Button variant="outline" size="sm" onClick={() => setRadius(25)}>
                      Search 25 km
                    </Button>
                  }
                />
              )}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}

function FilterChip({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
        active
          ? 'border-primary bg-primary/10 text-primary'
          : 'border-border text-muted-foreground hover:bg-accent',
      )}
    >
      {children}
    </button>
  );
}

function priceOf(station: { prices?: Array<{ fuel_type_id: number; price: number }> }, fuelTypeId?: number) {
  const prices = station.prices ?? [];

  if (!prices.length) return null;

  const match = fuelTypeId ? prices.find((price) => price.fuel_type_id === fuelTypeId) : prices[0];

  return match?.price ?? null;
}

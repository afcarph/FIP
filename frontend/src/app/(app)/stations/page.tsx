'use client';

import { Fuel, Search, Star, WifiOff } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useNearbyStations } from '@/hooks/use-api';
import { useGeolocation } from '@/hooks/use-geolocation';
import { formatCurrency, formatDistance } from '@/lib/utils';

export default function StationsPage() {
  const { latitude, longitude } = useGeolocation();
  const { data: stations, isLoading, isError } = useNearbyStations(latitude, longitude, 25);

  const [search, setSearch] = React.useState('');

  const filtered = React.useMemo(() => {
    if (!stations) return [];
    const term = search.trim().toLowerCase();
    if (!term) return stations;

    return stations.filter(
      (station) =>
        station.name.toLowerCase().includes(term) ||
        station.brand?.name.toLowerCase().includes(term) ||
        station.address.line.toLowerCase().includes(term),
    );
  }, [stations, search]);

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">Station directory</h1>
        <p className="text-sm text-muted-foreground">
          Brands, amenities, payment methods and live prices
        </p>
      </header>

      <div className="relative max-w-md">
        <Search
          className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search by name, brand or address"
          className="pl-9"
          aria-label="Search stations"
        />
      </div>

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, index) => (
            <Skeleton key={index} className="h-44 w-full rounded-xl" />
          ))}
        </div>
      ) : filtered.length ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {filtered.map((station) => (
            <Link key={station.id} href={`/stations/${station.slug}`}>
              <Card interactive className="h-full">
                <CardContent className="p-5">
                  <div className="mb-3 flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate font-medium">{station.name}</p>
                      <p className="truncate text-xs text-muted-foreground">
                        {station.brand?.name}
                        {station.distance_km !== undefined
                          ? ` · ${formatDistance(station.distance_km)}`
                          : ''}
                      </p>
                    </div>

                    {station.rating.count > 0 ? (
                      <div className="flex shrink-0 items-center gap-1 text-xs">
                        <Star className="size-3 fill-amber-400 text-amber-400" aria-hidden="true" />
                        <span className="tabular font-medium">{station.rating.average.toFixed(1)}</span>
                      </div>
                    ) : null}
                  </div>

                  <ul className="mb-3 space-y-1">
                    {(station.prices ?? []).slice(0, 3).map((price) => (
                      <li
                        key={price.fuel_type_id}
                        className="flex items-center justify-between gap-2 text-sm"
                      >
                        <span className="truncate text-muted-foreground">{price.fuel_type}</span>
                        <span className="tabular shrink-0 font-semibold">
                          {formatCurrency(price.price)}
                        </span>
                      </li>
                    ))}
                    {(station.prices ?? []).length === 0 ? (
                      <li className="text-sm text-muted-foreground">No prices reported yet</li>
                    ) : null}
                  </ul>

                  <div className="flex flex-wrap gap-1">
                    {station.is_24_hours ? <Badge variant="secondary">24 hours</Badge> : null}
                    {station.has_ev_charging ? <Badge variant="secondary">EV</Badge> : null}
                    {station.is_verified ? <Badge variant="success">Verified</Badge> : null}
                  </div>
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      ) : isError ? (
        <Card>
          <EmptyState
            icon={WifiOff}
            title="Could not load stations"
            description="The search could not be fetched. There may well be stations nearby — reload to try again."
          />
        </Card>
      ) : (
        <Card>
          <EmptyState
            icon={Fuel}
            title="No stations found"
            description={
              search ? 'Try a different search term.' : 'No stations have been listed in your area yet.'
            }
          />
        </Card>
      )}
    </div>
  );
}

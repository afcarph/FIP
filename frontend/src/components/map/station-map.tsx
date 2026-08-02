'use client';

import {
  AdvancedMarker,
  APIProvider,
  InfoWindow,
  Map,
  useMap,
} from '@vis.gl/react-google-maps';
import { Crosshair, Fuel, Layers, MapPin, Zap } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { cn, formatCurrency, formatDistance } from '@/lib/utils';
import type { Station } from '@/types/api';

interface StationMapProps {
  stations: Station[];
  centre: { lat: number; lng: number };
  fuelTypeId?: number;
  loading?: boolean;
  onRecentre?: () => void;
  onStationSelect?: (station: Station) => void;
  className?: string;
}

/**
 * Interactive station map.
 *
 * The markers carry the price as a label rather than a generic pin, because
 * the price *is* the information — a map of identical pins would force the
 * user to tap each one to learn anything. Colour ranks each station against
 * the visible set, so "cheap" is always relative to what is actually reachable
 * rather than to a fixed national threshold.
 */
export function StationMap({
  stations,
  centre,
  fuelTypeId,
  loading = false,
  onRecentre,
  onStationSelect,
  className,
}: StationMapProps) {
  const apiKey = process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY;

  if (!apiKey) {
    return (
      <Card className={cn('flex items-center justify-center p-10 text-center', className)}>
        <div>
          <MapPin className="mx-auto mb-3 size-8 text-muted-foreground" aria-hidden="true" />
          <p className="text-sm font-medium">Map unavailable</p>
          <p className="mt-1 text-xs text-muted-foreground">
            Set <code className="font-mono">NEXT_PUBLIC_GOOGLE_MAPS_API_KEY</code> to enable the map.
            Station prices are still listed below.
          </p>
        </div>
      </Card>
    );
  }

  return (
    <APIProvider apiKey={apiKey} libraries={['marker']}>
      <MapCanvas
        stations={stations}
        centre={centre}
        fuelTypeId={fuelTypeId}
        loading={loading}
        onRecentre={onRecentre}
        onStationSelect={onStationSelect}
        className={className}
      />
    </APIProvider>
  );
}

function MapCanvas({
  stations,
  centre,
  fuelTypeId,
  loading,
  onRecentre,
  onStationSelect,
  className,
}: StationMapProps) {
  const map = useMap();
  const [selected, setSelected] = React.useState<Station | null>(null);
  const [showHeat, setShowHeat] = React.useState(false);

  /**
   * Rank thresholds across the currently visible stations. Terciles rather
   * than fixed prices: on an expensive island, "cheap" still has to mean
   * something locally.
   */
  const thresholds = React.useMemo(() => {
    const prices = stations
      .map((station) => priceFor(station, fuelTypeId))
      .filter((price): price is number => price !== null)
      .sort((a, b) => a - b);

    if (prices.length < 3) return null;

    return {
      cheap: prices[Math.floor(prices.length / 3)]!,
      dear: prices[Math.floor((prices.length * 2) / 3)]!,
    };
  }, [stations, fuelTypeId]);

  const recentre = React.useCallback(() => {
    map?.panTo(centre);
    map?.setZoom(14);
    onRecentre?.();
  }, [map, centre, onRecentre]);

  return (
    <div className={cn('relative overflow-hidden rounded-xl border', className)}>
      <Map
        defaultCenter={centre}
        defaultZoom={14}
        mapId="fip-station-map"
        gestureHandling="greedy"
        disableDefaultUI
        zoomControl
        className="size-full"
        style={{ minHeight: 420 }}
      >
        {/* The user's own position, visually distinct from any station. */}
        <AdvancedMarker position={centre} title="Your location">
          <span className="relative flex size-4">
            <span className="absolute inline-flex size-full animate-pulse-ring rounded-full bg-primary opacity-60" />
            <span className="relative inline-flex size-4 rounded-full border-2 border-background bg-primary" />
          </span>
        </AdvancedMarker>

        {stations.map((station) => {
          const price = priceFor(station, fuelTypeId);
          const rank = rankOf(price, thresholds);

          return (
            <AdvancedMarker
              key={station.id}
              position={{ lat: station.latitude, lng: station.longitude }}
              title={station.name}
              onClick={() => {
                setSelected(station);
                onStationSelect?.(station);
              }}
            >
              <div
                className={cn(
                  'flex items-center gap-1 rounded-full border-2 border-background px-2 py-1 text-xs font-semibold shadow-md transition-transform hover:scale-110',
                  rank === 'cheap' && 'bg-price-down text-white',
                  rank === 'mid' && 'bg-chart-3 text-white',
                  rank === 'dear' && 'bg-price-up text-white',
                  rank === 'unknown' && 'bg-muted text-muted-foreground',
                )}
              >
                {station.has_ev_charging ? <Zap className="size-3" aria-hidden="true" /> : null}
                <span className="tabular">{price !== null ? `₱${price.toFixed(2)}` : '—'}</span>
              </div>
            </AdvancedMarker>
          );
        })}

        {selected ? (
          <InfoWindow
            position={{ lat: selected.latitude, lng: selected.longitude }}
            onCloseClick={() => setSelected(null)}
          >
            <StationPopover station={selected} fuelTypeId={fuelTypeId} />
          </InfoWindow>
        ) : null}
      </Map>

      {/* Floating controls, kept off the bottom edge so they clear mobile chrome. */}
      <div className="absolute right-3 top-3 flex flex-col gap-2">
        <Button variant="glass" size="icon" onClick={recentre} aria-label="Centre on my location">
          <Crosshair aria-hidden="true" />
        </Button>
        <Button
          variant="glass"
          size="icon"
          onClick={() => setShowHeat((value) => !value)}
          aria-pressed={showHeat}
          aria-label="Toggle price legend"
        >
          <Layers aria-hidden="true" />
        </Button>
      </div>

      {showHeat ? (
        <Card glass className="absolute bottom-3 left-3 p-3 text-xs">
          <p className="mb-2 font-medium">Price ranking</p>
          <ul className="space-y-1.5">
            {[
              ['bg-price-down', 'Cheapest third'],
              ['bg-chart-3', 'Middle third'],
              ['bg-price-up', 'Dearest third'],
            ].map(([colour, label]) => (
              <li key={label} className="flex items-center gap-2">
                <span className={cn('size-3 rounded-full', colour)} aria-hidden="true" />
                <span className="text-muted-foreground">{label}</span>
              </li>
            ))}
          </ul>
          <p className="mt-2 max-w-40 text-[11px] text-muted-foreground">
            Ranked against the stations currently in view.
          </p>
        </Card>
      ) : null}

      {loading ? (
        <div className="absolute inset-x-0 top-0 h-0.5 overflow-hidden bg-primary/20">
          <div className="h-full w-1/3 animate-shimmer bg-primary" />
        </div>
      ) : null}

      <div className="glass absolute bottom-3 right-3 rounded-lg px-3 py-1.5 text-xs">
        <span className="tabular font-medium">{stations.length}</span>{' '}
        <span className="text-muted-foreground">stations shown</span>
      </div>
    </div>
  );
}

function StationPopover({ station, fuelTypeId }: { station: Station; fuelTypeId?: number }) {
  return (
    <div className="min-w-52 max-w-64 p-1">
      <p className="font-semibold">{station.name}</p>
      <p className="mb-2 text-xs text-muted-foreground">
        {station.brand?.name}
        {station.distance_km !== undefined ? ` · ${formatDistance(station.distance_km)}` : ''}
      </p>

      <ul className="space-y-1">
        {(station.prices ?? [])
          .filter((price) => !fuelTypeId || price.fuel_type_id === fuelTypeId)
          .map((price) => (
            <li key={price.fuel_type_id} className="flex items-center justify-between gap-3 text-sm">
              <span className="truncate text-muted-foreground">{price.fuel_type}</span>
              <span className="tabular shrink-0 font-semibold">{formatCurrency(price.price)}</span>
            </li>
          ))}
      </ul>

      <div className="mt-2 flex flex-wrap gap-1">
        {station.is_24_hours ? <Badge variant="secondary">24 hours</Badge> : null}
        {station.has_ev_charging ? <Badge variant="secondary">EV charging</Badge> : null}
        {station.prices?.some((price) => price.is_stale) ? (
          <Badge variant="warning">Price may be stale</Badge>
        ) : null}
      </div>

      <a
        href={`/stations/${station.slug}`}
        className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
      >
        <Fuel className="size-3" aria-hidden="true" />
        View station
      </a>
    </div>
  );
}

function priceFor(station: Station, fuelTypeId?: number): number | null {
  const prices = station.prices ?? [];

  if (prices.length === 0) return null;

  const match = fuelTypeId
    ? prices.find((price) => price.fuel_type_id === fuelTypeId)
    : prices[0];

  return match?.price ?? null;
}

function rankOf(
  price: number | null,
  thresholds: { cheap: number; dear: number } | null,
): 'cheap' | 'mid' | 'dear' | 'unknown' {
  if (price === null) return 'unknown';
  if (!thresholds) return 'mid';
  if (price <= thresholds.cheap) return 'cheap';
  if (price >= thresholds.dear) return 'dear';
  return 'mid';
}

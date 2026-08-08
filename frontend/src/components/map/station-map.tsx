'use client';

import * as maplibregl from 'maplibre-gl';
import type { Map as MapLibreMap, Marker as MapLibreMarker } from 'maplibre-gl';
import { Crosshair, MapPin, Navigation } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
  DEFAULT_VIEW,
  googleMapsUrl,
  hasPlottableCoordinates,
  isProviderConfigured,
  mapStyleUrl,
} from '@/lib/map-config';
import { cn, formatDistance } from '@/lib/utils';
import type { Station } from '@/types/api';

import 'maplibre-gl/dist/maplibre-gl.css';

/**
 * The station map.
 *
 * MapLibre GL JS over an OSM-derived vector style. The renderer and the tile
 * provider are separate on purpose — MapLibre consumes any style JSON, so the
 * provider is a URL in the environment and swapping it does not touch this
 * file. See lib/map-config.
 *
 * Two things this component will not do. It never calls a Google service:
 * "Show location" and "Get directions" are plain google.com/maps URLs, which
 * need no key and cost nothing. And it never blocks the page — if the style
 * fails to load, the map area says so and the station list either side of it
 * keeps working, because a directory that dies with its basemap is worse than
 * one with no map at all.
 */

interface StationMapProps {
  stations: Station[];
  centre?: { latitude: number; longitude: number } | null;
  selectedId?: number | null;
  onSelect?: (station: Station) => void;
  onUseMyLocation?: () => void;
  locating?: boolean;
  className?: string;
}

function Unavailable({ detail }: { detail: string }) {
  return (
    <Card className="flex h-full min-h-[320px] items-center justify-center p-6">
      <div className="text-center">
        <MapPin className="mx-auto size-8 text-muted-foreground" aria-hidden />
        <p className="mt-2 text-sm font-medium">Map temporarily unavailable</p>
        <p className="mt-1 max-w-sm text-xs text-muted-foreground">{detail}</p>
      </div>
    </Card>
  );
}

export function StationMap({
  stations,
  centre,
  selectedId,
  onSelect,
  onUseMyLocation,
  locating = false,
  className,
}: StationMapProps) {
  const container = React.useRef<HTMLDivElement | null>(null);
  const map = React.useRef<MapLibreMap | null>(null);
  const markers = React.useRef<Map<number, MapLibreMarker>>(new Map());
  const userMarker = React.useRef<MapLibreMarker | null>(null);

  const [failed, setFailed] = React.useState(false);

  // Only stations we can honestly place. A missing or impossible coordinate is
  // skipped rather than defaulted — a marker in the wrong place is worse than
  // no marker, and 0,0 would drag the whole view into the Atlantic.
  const plottable = React.useMemo(
    () => stations.filter((s) => hasPlottableCoordinates(s.latitude, s.longitude)),
    [stations],
  );

  React.useEffect(() => {
    if (map.current || !container.current) return;

    let instance: MapLibreMap;

    try {
      instance = new maplibregl.Map({
        container: container.current,
        style: mapStyleUrl(),
        center: [centre?.longitude ?? DEFAULT_VIEW.longitude, centre?.latitude ?? DEFAULT_VIEW.latitude],
        zoom: DEFAULT_VIEW.zoom,
        attributionControl: { compact: true },
      });
    } catch {
      // A style URL that is malformed, or a browser without WebGL.
      setFailed(true);

      return;
    }

    instance.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    // OSM's licence requires attribution, and the provider's terms require
    // theirs. MapLibre reads both from the style; this keeps the control on
    // screen rather than letting a compact layout hide it.
    instance.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left');

    instance.on('error', (event) => {
      // Style or tile failures arrive here rather than as a throw. Anything
      // that stops the basemap loading flips the panel; a single missing tile
      // does not.
      if (event?.error && String(event.error).match(/style|fetch|Failed|403|401/i)) {
        setFailed(true);
      }
    });

    map.current = instance;

    // Captured now, not read at cleanup time: by then the ref may point at a
    // different object, and clearing the wrong map leaks the old markers.
    const registry = markers.current;

    return () => {
      instance.remove();
      map.current = null;
      registry.clear();
    };
    // Deliberately mounts once. Centre changes are handled below by easeTo,
    // because re-creating the map on every position update would discard the
    // user's pan and zoom.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Station markers, reconciled rather than rebuilt: tearing every marker down
  // on each render makes the map flicker and loses the open popup.
  React.useEffect(() => {
    const instance = map.current;

    if (!instance || failed) return;

    const wanted = new Set(plottable.map((station) => station.id));

    for (const [id, marker] of markers.current) {
      if (!wanted.has(id)) {
        marker.remove();
        markers.current.delete(id);
      }
    }

    for (const station of plottable) {
      if (markers.current.has(station.id)) continue;

      const element = document.createElement('button');
      element.type = 'button';
      element.setAttribute('aria-label', station.name);
      element.className =
        'grid size-7 place-items-center rounded-full border-2 border-white bg-primary text-[10px] font-bold text-primary-foreground shadow-md';
      element.textContent = (station.brand?.name ?? station.name).slice(0, 1).toUpperCase();

      if (station.brand?.color_hex) element.style.backgroundColor = station.brand.color_hex;

      element.addEventListener('click', (event) => {
        event.stopPropagation();
        onSelect?.(station);
      });

      markers.current.set(
        station.id,
        new maplibregl.Marker({ element })
          .setLngLat([station.longitude, station.latitude])
          .addTo(instance),
      );
    }
  }, [plottable, onSelect, failed]);

  // The user's own position, when they have chosen to share it.
  React.useEffect(() => {
    const instance = map.current;

    if (!instance || failed) return;

    userMarker.current?.remove();
    userMarker.current = null;

    if (!centre) return;

    const element = document.createElement('div');
    element.className = 'size-3.5 rounded-full border-2 border-white bg-sky-500 shadow';
    element.setAttribute('aria-label', 'Your location');

    userMarker.current = new maplibregl.Marker({ element })
      .setLngLat([centre.longitude, centre.latitude])
      .addTo(instance);

    instance.easeTo({ center: [centre.longitude, centre.latitude], zoom: 13, duration: 600 });
  }, [centre, failed]);

  // Bring a selected station into view without stealing the whole viewport.
  React.useEffect(() => {
    const instance = map.current;

    if (!instance || failed || selectedId == null) return;

    const station = plottable.find((candidate) => candidate.id === selectedId);

    if (station) {
      instance.easeTo({ center: [station.longitude, station.latitude], duration: 500 });
    }
  }, [selectedId, plottable, failed]);

  if (failed) {
    return (
      <Unavailable
        detail={
          isProviderConfigured()
            ? 'The map tiles could not be loaded. Everything else on this page still works — you can search, open a station, and get directions.'
            : 'No map style is configured. Set NEXT_PUBLIC_MAP_STYLE_URL. Everything else on this page still works.'
        }
      />
    );
  }

  return (
    <div className={cn('relative overflow-hidden rounded-xl border', className)}>
      <div ref={container} className="h-full min-h-[320px] w-full" data-testid="maplibre-canvas" />

      {onUseMyLocation ? (
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={onUseMyLocation}
          loading={locating}
          className="absolute left-3 top-3 shadow"
        >
          <Crosshair aria-hidden />
          Use my location
        </Button>
      ) : null}

      {!isProviderConfigured() ? (
        <p className="absolute bottom-2 right-2 rounded bg-background/90 px-2 py-1 text-[10px] text-muted-foreground">
          Development basemap — set NEXT_PUBLIC_MAP_STYLE_URL for production tiles
        </p>
      ) : null}
    </div>
  );
}

/** The link pair shown against a station. Neither needs a key or a quota. */
export function StationLinks({
  station,
  origin,
}: {
  station: Pick<Station, 'latitude' | 'longitude'>;
  origin?: { latitude: number; longitude: number } | null;
}) {
  return (
    <div className="flex flex-wrap gap-2">
      <Button asChild variant="outline" size="sm">
        <a
          href={googleMapsUrl.showLocation(station.latitude, station.longitude)}
          target="_blank"
          rel="noreferrer noopener"
        >
          <MapPin aria-hidden />
          Show location
        </a>
      </Button>

      <Button asChild variant="outline" size="sm">
        <a
          href={googleMapsUrl.directions(station.latitude, station.longitude, origin)}
          target="_blank"
          rel="noreferrer noopener"
        >
          <Navigation aria-hidden />
          Get directions
        </a>
      </Button>
    </div>
  );
}

export { formatDistance };

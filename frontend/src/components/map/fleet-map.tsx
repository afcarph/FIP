'use client';

import { MapPin } from 'lucide-react';
import * as React from 'react';

import * as maplibregl from './maplibre';
import type { Map as MapLibreMap, Marker as MapLibreMarker } from './maplibre';

import { Card } from '@/components/ui/card';
import {
  DEFAULT_VIEW,
  hasPlottableCoordinates,
  isProviderConfigured,
  mapStyleUrl,
} from '@/lib/map-config';
import { cn } from '@/lib/utils';
import type { VehicleLocation } from '@/types/api';

import 'maplibre-gl/dist/maplibre-gl.css';

/**
 * Where the fleet is now.
 *
 * One marker per vehicle, carrying the plate, because a fleet is tens of
 * vehicles and an operator reads it by plate — not the clustered dots the
 * station directory uses, which answer "how many are near here" rather than
 * "where is NOV1616". DOM markers also keep the labels legible and the click
 * targets honest at any zoom.
 *
 * This is a current-position view and nothing else. It does not draw tracks,
 * does not replay a day, and does not follow a vehicle — those are separate
 * questions about a person's movements, and they carry their own permission
 * (`devices.location.history`) which this page never asks for.
 */

/**
 * "Show me this vehicle" — an event, not a selection.
 *
 * `at` is what makes asking twice work. An operator who has panned across the
 * city clicks the same vehicle in the list again to get back to it, and a
 * plain id would compare equal to the one already held and move nothing.
 */
export interface FocusRequest {
  vehicleId: number;
  at: number;
}

interface FleetMapProps {
  vehicles: VehicleLocation[];
  focus?: FocusRequest | null;
  onSelect?: (vehicle: VehicleLocation) => void;
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

/**
 * The marker for one vehicle.
 *
 * Built as a DOM node rather than a style layer so the plate can be read
 * without clicking. A stale position is drawn in a muted amber and says so on
 * hover: it is still the last thing we know, but an operator dispatching
 * against it needs to see that it is old before they act on it.
 */
function createMarkerElement(): { element: HTMLButtonElement; dot: HTMLSpanElement; label: HTMLSpanElement } {
  const element = document.createElement('button');
  const dot = document.createElement('span');
  const label = document.createElement('span');

  element.type = 'button';
  dot.setAttribute('aria-hidden', 'true');
  element.append(dot, label);

  return { element, dot, label };
}

/** Applied on creation and again on every refresh, to the same nodes. */
function paintMarker(
  parts: { element: HTMLButtonElement; dot: HTMLSpanElement; label: HTMLSpanElement },
  vehicle: VehicleLocation,
  selected: boolean,
): void {
  const name = vehicle.plate_number ?? `Vehicle ${vehicle.vehicle_id}`;

  parts.element.className = cn(
    'flex items-center gap-1.5 rounded-full border px-2 py-1 text-xs font-medium shadow-sm transition',
    vehicle.is_fresh
      ? 'border-emerald-600/30 bg-emerald-600 text-white'
      : 'border-amber-600/40 bg-amber-100 text-amber-900',
    selected && 'ring-2 ring-offset-1 ring-sky-500',
  );
  parts.dot.className = cn('size-1.5 rounded-full', vehicle.is_fresh ? 'bg-white' : 'bg-amber-600');
  parts.label.textContent = name;

  parts.element.setAttribute(
    'aria-label',
    `${name}${vehicle.is_fresh ? '' : ' — position is stale'}`,
  );
  parts.element.title = vehicle.is_fresh
    ? 'Reporting now'
    : 'This is the last known position, and it is no longer current';
}

interface TrackedMarker {
  marker: MapLibreMarker;
  parts: ReturnType<typeof createMarkerElement>;
}

export function FleetMap({ vehicles, focus, onSelect, className }: FleetMapProps) {
  const selectedId = focus?.vehicleId ?? null;

  const container = React.useRef<HTMLDivElement | null>(null);
  const map = React.useRef<MapLibreMap | null>(null);
  const markers = React.useRef<Map<number, TrackedMarker>>(new Map());
  const latest = React.useRef<Map<number, VehicleLocation>>(new Map());
  const hasFitted = React.useRef(false);

  const [failed, setFailed] = React.useState(false);
  const [ready, setReady] = React.useState(false);

  const onSelectRef = React.useRef(onSelect);

  React.useEffect(() => {
    onSelectRef.current = onSelect;
  }, [onSelect]);

  // Only vehicles we can honestly place. A device that has reported a
  // nonsensical fix is left off the map rather than drawn somewhere wrong —
  // and it still appears in the list beside it, so it is not hidden.
  const plottable = React.useMemo(
    () => vehicles.filter((v) => hasPlottableCoordinates(v.latitude, v.longitude)),
    [vehicles],
  );

  latest.current = new Map(plottable.map((vehicle) => [vehicle.vehicle_id, vehicle]));

  React.useEffect(() => {
    if (map.current || !container.current) return;

    let instance: MapLibreMap;

    try {
      instance = new maplibregl.Map({
        container: container.current,
        style: mapStyleUrl(),
        center: [DEFAULT_VIEW.longitude, DEFAULT_VIEW.latitude],
        zoom: DEFAULT_VIEW.zoom,
        attributionControl: { compact: true },
      });
    } catch {
      // A malformed style URL, or a browser without WebGL.
      setFailed(true);

      return;
    }

    instance.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    instance.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left');

    instance.on('error', (event) => {
      if (event?.error && String(event.error).match(/style|fetch|Failed|403|401/i)) {
        setFailed(true);
      }
    });

    instance.on('load', () => setReady(true));

    map.current = instance;

    const registry = markers.current;

    return () => {
      instance.remove();
      map.current = null;
      registry.clear();
      hasFitted.current = false;
    };
    // Mounts once. Positions arrive on a timer and are applied below;
    // re-creating the map for each refresh would throw away the operator's
    // pan and zoom every minute.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Markers are reconciled against the previous set, not rebuilt. A fleet
  // refreshes every minute; tearing down every marker each time makes the
  // whole fleet blink and takes the marker out from under a pointer that is
  // already on it. Only vehicles that have actually left get removed.
  React.useEffect(() => {
    const instance = map.current;

    if (!instance || failed || !ready) return;

    const registry = markers.current;
    const seen = new Set<number>();

    for (const vehicle of plottable) {
      seen.add(vehicle.vehicle_id);

      const existing = registry.get(vehicle.vehicle_id);
      const selected = vehicle.vehicle_id === selectedId;

      if (existing) {
        existing.marker.setLngLat([vehicle.longitude as number, vehicle.latitude as number]);
        paintMarker(existing.parts, vehicle, selected);

        continue;
      }

      const parts = createMarkerElement();

      paintMarker(parts, vehicle, selected);

      // Reads the id, not the object: this listener outlives the refresh that
      // created it, and closing over a position would hand the page a fix
      // that has since been replaced.
      parts.element.addEventListener('click', (event) => {
        event.stopPropagation();

        const current = latest.current.get(vehicle.vehicle_id);

        if (current) onSelectRef.current?.(current);
      });

      registry.set(vehicle.vehicle_id, {
        parts,
        marker: new maplibregl.Marker({ element: parts.element })
          .setLngLat([vehicle.longitude as number, vehicle.latitude as number])
          .addTo(instance),
      });
    }

    for (const [id, tracked] of registry) {
      if (seen.has(id)) continue;

      tracked.marker.remove();
      registry.delete(id);
    }
  }, [plottable, selectedId, failed, ready]);

  // Frame the fleet once, on the first load that has anything to frame.
  // Afterwards the camera belongs to the operator: a view that re-fits itself
  // every refresh cannot be zoomed into.
  React.useEffect(() => {
    const instance = map.current;

    if (!instance || failed || !ready || hasFitted.current || plottable.length === 0) return;

    hasFitted.current = true;

    const only = plottable.length === 1 ? plottable[0] : undefined;

    if (only) {
      instance.easeTo({
        center: [only.longitude as number, only.latitude as number],
        zoom: 14,
        duration: 0,
      });

      return;
    }

    const bounds = new maplibregl.LngLatBounds();

    for (const vehicle of plottable) {
      bounds.extend([vehicle.longitude as number, vehicle.latitude as number]);
    }

    instance.fitBounds(bounds, { padding: 64, maxZoom: 15, duration: 0 });
  }, [plottable, failed, ready]);

  // Bring the vehicle just asked for into view, without wrenching the zoom.
  // Keyed on the request rather than on which vehicle is selected: asking for
  // the same one again is the normal way back after panning the map around.
  React.useEffect(() => {
    const instance = map.current;

    if (!instance || failed || !focus) return;

    const vehicle = latest.current.get(focus.vehicleId);

    if (!vehicle) return;

    instance.easeTo({
      center: [vehicle.longitude as number, vehicle.latitude as number],
      duration: 500,
    });
    // `plottable` is deliberately absent: a refresh that leaves the fleet in
    // place must not drag the camera back to the last vehicle clicked.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focus?.vehicleId, focus?.at, failed]);

  if (failed) {
    return (
      <Unavailable
        detail={
          isProviderConfigured()
            ? 'The map tiles could not be loaded. The list of vehicles beside it still shows where each one was last reported.'
            : 'No map style is configured. Set NEXT_PUBLIC_MAP_STYLE_URL. The list of vehicles beside it still works.'
        }
      />
    );
  }

  return (
    <div className={cn('relative overflow-hidden rounded-xl border', className)}>
      <div ref={container} className="h-full min-h-[320px] w-full" data-testid="fleet-map-canvas" />

      {!isProviderConfigured() ? (
        <p className="absolute bottom-2 right-2 rounded bg-background/90 px-2 py-1 text-[10px] text-muted-foreground">
          Development basemap — set NEXT_PUBLIC_MAP_STYLE_URL for production tiles
        </p>
      ) : null}
    </div>
  );
}

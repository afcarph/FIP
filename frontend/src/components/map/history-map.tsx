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
import type { DeviceLocationPoint } from '@/types/api';

import 'maplibre-gl/dist/maplibre-gl.css';

/**
 * Where one vehicle has been.
 *
 * The hard part of drawing a track is not the line, it is what the line
 * claims. Fixes arrive every couple of minutes at best, and a phone that
 * spends an hour in a basement car park leaves a gap. Joining those two ends
 * draws a road that was never taken — through buildings, across a river — and
 * it looks exactly as authoritative as the parts that are real.
 *
 * So the track is a MultiLineString, cut wherever consecutive fixes are far
 * enough apart in time that the path between them is unknown. Each run of
 * fixes is drawn solid; the gaps are drawn as a dashed line that says "we do
 * not know what happened here" rather than pretending nothing did.
 */

const TRACK = 'fip-track';
const GAPS = 'fip-track-gaps';
const POINTS = 'fip-track-points';

/**
 * Longer than this between two fixes and the path between them is a guess.
 *
 * Devices sample every 120s by default and skip a sample when the vehicle has
 * barely moved, so a parked vehicle produces long silences that are not gaps
 * in knowledge. Ten minutes is comfortably clear of an ordinary skipped
 * sample and short enough to catch a genuine hole.
 */
const GAP_SECONDS = 600;

export interface TrackSegment {
  coordinates: Array<[number, number]>;
}

interface HistoryMapProps {
  points: DeviceLocationPoint[];
  /** The point the replay is currently sitting on, if a replay is running. */
  cursor?: DeviceLocationPoint | null;
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

/** Only the fixes that can be believed, in the order they were recorded. */
export function plottablePoints(points: DeviceLocationPoint[]): DeviceLocationPoint[] {
  return points.filter((point) => hasPlottableCoordinates(point.latitude, point.longitude));
}

/**
 * Split a track wherever the time between fixes leaves the path unknown.
 *
 * Exported because this is the judgement the map makes about the data, and it
 * is worth being able to test it without a WebGL context.
 */
export function splitIntoSegments(
  points: DeviceLocationPoint[],
  gapSeconds: number = GAP_SECONDS,
): TrackSegment[] {
  const segments: TrackSegment[] = [];
  let current: Array<[number, number]> = [];
  let previousAt: number | null = null;

  for (const point of points) {
    const at = point.recorded_at ? Date.parse(point.recorded_at) : Number.NaN;
    const coordinate: [number, number] = [point.longitude, point.latitude];

    const broken =
      previousAt !== null &&
      Number.isFinite(at) &&
      (at - previousAt) / 1000 > gapSeconds;

    if (broken && current.length > 0) {
      segments.push({ coordinates: current });
      current = [];
    }

    current.push(coordinate);
    if (Number.isFinite(at)) previousAt = at;
  }

  if (current.length > 0) segments.push({ coordinates: current });

  // A single fix is a place, not a path. It still gets a point drawn for it,
  // but it is not a line, and returning it as one would draw nothing while
  // implying travel.
  return segments.filter((segment) => segment.coordinates.length > 1);
}

/** The dashed connectors that stand in for what is not known. */
export function gapSegments(
  points: DeviceLocationPoint[],
  gapSeconds: number = GAP_SECONDS,
): TrackSegment[] {
  const gaps: TrackSegment[] = [];

  for (let index = 1; index < points.length; index += 1) {
    const before = points[index - 1]!;
    const after = points[index]!;
    const a = before.recorded_at ? Date.parse(before.recorded_at) : Number.NaN;
    const b = after.recorded_at ? Date.parse(after.recorded_at) : Number.NaN;

    if (!Number.isFinite(a) || !Number.isFinite(b)) continue;
    if ((b - a) / 1000 <= gapSeconds) continue;

    gaps.push({
      coordinates: [
        [before.longitude, before.latitude],
        [after.longitude, after.latitude],
      ],
    });
  }

  return gaps;
}

function lineCollection(segments: TrackSegment[]) {
  return {
    type: 'FeatureCollection' as const,
    features: segments.map((segment) => ({
      type: 'Feature' as const,
      properties: {},
      geometry: { type: 'LineString' as const, coordinates: segment.coordinates },
    })),
  };
}

export function HistoryMap({ points, cursor, className }: HistoryMapProps) {
  const container = React.useRef<HTMLDivElement | null>(null);
  const map = React.useRef<MapLibreMap | null>(null);
  const cursorMarker = React.useRef<MapLibreMarker | null>(null);
  const endpoints = React.useRef<MapLibreMarker[]>([]);
  const fittedFor = React.useRef<string | null>(null);

  const [failed, setFailed] = React.useState(false);
  const [ready, setReady] = React.useState(false);

  const plottable = React.useMemo(() => plottablePoints(points), [points]);

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

    return () => {
      instance.remove();
      map.current = null;
      cursorMarker.current = null;
      endpoints.current = [];
      fittedFor.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // The track itself: solid where fixes are close enough to join, dashed
  // across the holes, with every recorded fix marked so an operator can see
  // how much of the line is measured and how much is interpolation.
  React.useEffect(() => {
    const instance = map.current;

    if (!instance || failed || !ready) return;

    const track = lineCollection(splitIntoSegments(plottable));
    const gaps = lineCollection(gapSegments(plottable));
    const dots = {
      type: 'FeatureCollection' as const,
      features: plottable.map((point) => ({
        type: 'Feature' as const,
        properties: { id: point.id },
        geometry: { type: 'Point' as const, coordinates: [point.longitude, point.latitude] },
      })),
    };

    const existing = instance.getSource(TRACK) as maplibregl.GeoJSONSource | undefined;

    if (existing) {
      existing.setData(track);
      (instance.getSource(GAPS) as maplibregl.GeoJSONSource | undefined)?.setData(gaps);
      (instance.getSource(POINTS) as maplibregl.GeoJSONSource | undefined)?.setData(dots);
    } else {
      instance.addSource(TRACK, { type: 'geojson', data: track });
      instance.addSource(GAPS, { type: 'geojson', data: gaps });
      instance.addSource(POINTS, { type: 'geojson', data: dots });

      instance.addLayer({
        id: GAPS,
        type: 'line',
        source: GAPS,
        layout: { 'line-cap': 'round' },
        paint: {
          'line-color': '#94a3b8',
          'line-width': 2,
          'line-dasharray': [1, 2],
        },
      });

      instance.addLayer({
        id: TRACK,
        type: 'line',
        source: TRACK,
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': '#0284c7', 'line-width': 4, 'line-opacity': 0.85 },
      });

      instance.addLayer({
        id: POINTS,
        type: 'circle',
        source: POINTS,
        paint: {
          'circle-radius': 3.5,
          'circle-color': '#0369a1',
          'circle-stroke-width': 1.5,
          'circle-stroke-color': '#ffffff',
        },
      });
    }

    for (const marker of endpoints.current) marker.remove();
    endpoints.current = [];

    const first = plottable[0];
    const last = plottable[plottable.length - 1];

    // Start and end are labelled because a track drawn without them can be
    // read backwards, and "where did it finish" is usually the question.
    for (const [point, label, colour] of [
      [first, 'Start', '#15803d'],
      [last, 'End', '#b91c1c'],
    ] as const) {
      if (!point) continue;
      if (first === last && label === 'End') continue;

      const element = document.createElement('div');
      const badge = document.createElement('span');

      badge.className =
        'rounded-full border border-white/70 px-2 py-0.5 text-[10px] font-semibold text-white shadow';
      badge.style.background = colour;
      badge.textContent = label;
      element.append(badge);

      endpoints.current.push(
        new maplibregl.Marker({ element })
          .setLngLat([point.longitude, point.latitude])
          .addTo(instance),
      );
    }

    // Frame each track once. Re-fitting on every render would fight the
    // operator's zoom while they follow a replay.
    const signature = plottable.map((point) => point.id).join(',');

    if (signature !== fittedFor.current && plottable.length > 0) {
      fittedFor.current = signature;

      const bounds = new maplibregl.LngLatBounds();

      for (const point of plottable) bounds.extend([point.longitude, point.latitude]);

      instance.fitBounds(bounds, { padding: 64, maxZoom: 16, duration: 0 });
    }
  }, [plottable, failed, ready]);

  // The replay position. A marker rather than a layer: it is one moving thing,
  // and it has to stay legible on top of the track it is travelling.
  React.useEffect(() => {
    const instance = map.current;

    if (!instance || failed) return;

    if (!cursor || !hasPlottableCoordinates(cursor.latitude, cursor.longitude)) {
      cursorMarker.current?.remove();
      cursorMarker.current = null;

      return;
    }

    if (!cursorMarker.current) {
      const element = document.createElement('div');
      const dot = document.createElement('span');

      dot.className = 'block size-4 rounded-full border-2 border-white bg-sky-500 shadow-lg';
      element.append(dot);

      cursorMarker.current = new maplibregl.Marker({ element }).addTo(instance);
    }

    cursorMarker.current.setLngLat([cursor.longitude, cursor.latitude]);
  }, [cursor, failed]);

  if (failed) {
    return (
      <Unavailable
        detail={
          isProviderConfigured()
            ? 'The map tiles could not be loaded. The list of positions beside it still works.'
            : 'No map style is configured. Set NEXT_PUBLIC_MAP_STYLE_URL.'
        }
      />
    );
  }

  return (
    <div className={`relative overflow-hidden rounded-xl border ${className ?? ''}`}>
      <div ref={container} className="h-full min-h-[320px] w-full" data-testid="history-map-canvas" />
    </div>
  );
}

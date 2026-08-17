import { act, render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The history map's wiring.
 *
 * The pure track logic is covered in history-map.test.ts. This file exists
 * because that was not enough: the replay marker was added to the map before
 * it had a position, MapLibre projects a marker the moment it is added, and
 * the page died with "Cannot read properties of undefined (reading 'lng')" —
 * a crash no amount of testing the geometry helpers could have found.
 *
 * So the mock refuses what the real library refuses.
 */

interface MarkerRecord {
  lngLat: [number, number] | null;
  element: HTMLElement;
  removed: boolean;
}

const markerInstances: MarkerRecord[] = [];
const layers: string[] = [];
const sources: Record<string, unknown> = {};
// A container rather than a reassigned binding: the mock factory writes from
// its own closure, and mutating a shared object is the reliable way across it.
const framing: { last: { padding?: number } | null } = { last: null };

vi.mock('maplibre-gl', () => {
  class Marker {
    record: MarkerRecord;

    constructor(options?: { element?: HTMLElement }) {
      this.record = {
        lngLat: null,
        element: options?.element ?? document.createElement('div'),
        removed: false,
      };
    }

    setLngLat(value: [number, number]) {
      this.record.lngLat = value;

      return this;
    }

    addTo() {
      // What MapLibre does: adding a marker projects it straight away, and a
      // marker with no position throws on `lngLat.lng`.
      if (!this.record.lngLat) {
        throw new TypeError("Cannot read properties of undefined (reading 'lng')");
      }

      this.record.element.classList.add('maplibregl-marker');
      markerInstances.push(this.record);

      return this;
    }

    remove() {
      this.record.removed = true;
    }
  }

  class LngLatBounds {
    points: Array<[number, number]> = [];

    extend(point: [number, number]) {
      this.points.push(point);

      return this;
    }
  }

  class Map {
    handlers: Record<string, Array<(event?: unknown) => void>> = {};

    constructor() {
      // The load event is what gates every layer this component adds.
      queueMicrotask(() => this.handlers.load?.forEach((handler) => handler()));
    }

    on(event: string, handler: (event?: unknown) => void) {
      (this.handlers[event] ??= []).push(handler);
    }

    addControl() {}
    addSource(id: string, source: unknown) {
      sources[id] = source;
    }
    getSource(id: string) {
      return sources[id]
        ? { setData: (data: unknown) => { sources[id] = data; } }
        : undefined;
    }
    addLayer(layer: { id: string }) {
      layers.push(layer.id);
    }
    fitBounds(_bounds: unknown, options: { padding?: number }) {
      framing.last = options;
    }
    remove() {}
  }

  return {
    Map,
    Marker,
    LngLatBounds,
    NavigationControl: class {},
    ScaleControl: class {},
    setWorkerUrl: vi.fn(),
  };
});

vi.mock('maplibre-gl/dist/maplibre-gl.css', () => ({}));

const { HistoryMap } = await import('@/components/map/history-map');

let id = 0;

function fix(minutes: number, overrides: Record<string, unknown> = {}) {
  return {
    id: (id += 1),
    vehicle_id: 4,
    latitude: 14.55 + minutes * 0.001,
    longitude: 121.02,
    accuracy_m: 10,
    altitude_m: null,
    speed_kph: 20,
    heading_deg: null,
    recorded_at: new Date(Date.UTC(2026, 7, 15, 8, minutes)).toISOString(),
    received_at: null,
    ...overrides,
  } as never;
}

/**
 * Let the map finish loading.
 *
 * The mocked map fires `load` on a microtask, which flips `ready` and lets the
 * layer and marker effect run. Inside `act` so React has actually processed
 * that update before anything is asserted — without it the assertions race the
 * effect and fail intermittently.
 */
async function settle() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

describe('HistoryMap', () => {
  beforeEach(() => {
    markerInstances.length = 0;
    layers.length = 0;
    framing.last = null;
    for (const key of Object.keys(sources)) delete sources[key];
  });

  it('renders its container', () => {
    const { getByTestId } = render(<HistoryMap points={[fix(0), fix(2)]} />);

    expect(getByTestId('history-map-canvas')).toBeTruthy();
  });

  it('places the replay marker without crashing', async () => {
    // The regression. The marker used to be added first and positioned after,
    // which threw inside MapLibre and took the page down the moment a vehicle
    // was chosen.
    const points = [fix(0), fix(2)];

    expect(() => render(<HistoryMap points={points} cursor={points[0]} />)).not.toThrow();

    await settle();

    const cursorMarker = markerInstances.find((marker) => marker.element.querySelector('span.bg-sky-500'));

    expect(cursorMarker?.lngLat).toEqual([121.02, 14.55]);
  });

  it('draws the track, the gaps and the fixes as separate layers', async () => {
    render(<HistoryMap points={[fix(0), fix(2), fix(40)]} />);
    await settle();

    expect(layers).toContain('fip-track');
    expect(layers).toContain('fip-track-gaps');
    expect(layers).toContain('fip-track-points');
  });

  it('marks where the track starts and ends', async () => {
    render(<HistoryMap points={[fix(0), fix(2)]} />);
    await settle();

    const labels = markerInstances.map((marker) => marker.element.textContent);

    expect(labels).toContain('Start');
    expect(labels).toContain('End');
  });

  it('does not label one fix as both start and end', async () => {
    // A single position is a place. Two badges stacked on it reads as a
    // journey that began and finished without moving.
    render(<HistoryMap points={[fix(0)]} />);
    await settle();

    const labels = markerInstances.map((marker) => marker.element.textContent);

    expect(labels).toEqual(['Start']);
  });

  it('frames the track once', async () => {
    render(<HistoryMap points={[fix(0), fix(2)]} />);
    await settle();

    expect(framing.last?.padding).toBe(64);
  });

  it('removes the replay marker when the replay stops', async () => {
    const points = [fix(0), fix(2)];
    const { rerender } = render(<HistoryMap points={points} cursor={points[0]} />);
    await settle();

    rerender(<HistoryMap points={points} cursor={null} />);

    const cursorMarker = markerInstances.find((marker) => marker.element.querySelector('span.bg-sky-500'));

    expect(cursorMarker?.removed).toBe(true);
  });
});

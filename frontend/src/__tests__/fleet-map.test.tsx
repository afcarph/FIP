import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The fleet map's wiring.
 *
 * MapLibre needs WebGL, which jsdom has none of, so the library is mocked and
 * what is asserted is the contract: one marker per placeable vehicle, a marker
 * that says when a position is stale, a click that reaches the page, and a
 * failing basemap that degrades instead of taking the list down with it.
 */

interface MarkerRecord {
  lngLat: [number, number];
  element: HTMLElement;
  removed: boolean;
}

const markerInstances: MarkerRecord[] = [];
let mapConstructorThrows = false;
let capturedErrorHandler: ((event: unknown) => void) | null = null;
let fitted: { padding?: number } | null = null;
let easedTo: Array<[number, number]> = [];
const workerUrls: string[] = [];

vi.mock('maplibre-gl', () => {
  class Marker {
    record: MarkerRecord;

    constructor(options?: { element?: HTMLElement }) {
      this.record = {
        lngLat: [0, 0],
        element: options?.element ?? document.createElement('div'),
        removed: false,
      };
    }

    setLngLat(value: [number, number]) {
      this.record.lngLat = value;

      return this;
    }

    addTo() {
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
    constructor() {
      if (mapConstructorThrows) throw new Error('WebGL unavailable');
    }

    addControl() {}

    on(event: string, handler?: unknown) {
      if (event === 'error') capturedErrorHandler = handler as (payload: unknown) => void;
      if (event === 'load' && typeof handler === 'function') (handler as () => void)();
    }

    fitBounds(_bounds: unknown, options: { padding?: number }) {
      fitted = options;
    }

    easeTo(options: { center: [number, number] }) {
      easedTo.push(options.center);
    }

    getCanvas() {
      return { style: {} };
    }

    remove() {}
  }

  return {
    Map,
    Marker,
    LngLatBounds,
    NavigationControl: class {},
    ScaleControl: class {},
    // Pinned at import time by components/map/maplibre.ts. Recorded rather
    // than ignored so the assertion below can prove a new map still gets it.
    setWorkerUrl: vi.fn((url: string) => workerUrls.push(url)),
  };
});

vi.mock('maplibre-gl/dist/maplibre-gl.css', () => ({}));

const { FleetMap } = await import('@/components/map/fleet-map');

function vehicle(overrides: Record<string, unknown> = {}) {
  return {
    vehicle_id: 7,
    plate_number: 'NOV1616',
    display_name: 'Hilux',
    device_id: 3,
    driver_name: 'Ramon Cruz',
    latitude: 14.5547,
    longitude: 121.0244,
    recorded_at: new Date().toISOString(),
    last_seen_at: new Date().toISOString(),
    is_fresh: true,
    ...overrides,
  } as never;
}

describe('FleetMap', () => {
  beforeEach(() => {
    markerInstances.length = 0;
    mapConstructorThrows = false;
    capturedErrorHandler = null;
    fitted = null;
    easedTo = [];
  });

  it('renders the map container', () => {
    render(<FleetMap vehicles={[vehicle()]} />);

    expect(screen.getByTestId('fleet-map-canvas')).toBeTruthy();
  });

  it('places one marker per vehicle, at its own coordinates', () => {
    render(<FleetMap vehicles={[vehicle(), vehicle({ vehicle_id: 8, longitude: 121.05 })]} />);

    expect(markerInstances).toHaveLength(2);
    expect(markerInstances[0]?.lngLat).toEqual([121.0244, 14.5547]);
    expect(markerInstances[1]?.lngLat).toEqual([121.05, 14.5547]);
  });

  it('labels the marker with the plate an operator would say out loud', () => {
    render(<FleetMap vehicles={[vehicle()]} />);

    expect(markerInstances[0]?.element.textContent).toContain('NOV1616');
  });

  it('says on the marker itself when a position is no longer current', () => {
    // The dot alone would read as "here it is". A stale fix is the last thing
    // known, not where the vehicle is, and dispatching against it sends
    // somebody to the wrong place.
    render(<FleetMap vehicles={[vehicle({ is_fresh: false })]} />);

    expect(markerInstances[0]?.element.getAttribute('aria-label')).toContain('stale');
  });

  it('skips a vehicle whose coordinates cannot be believed', () => {
    // 0,0 is what a failed import leaves behind. Drawing it puts a truck in
    // the Atlantic and drags the whole view with it.
    render(<FleetMap vehicles={[vehicle({ latitude: 0, longitude: 0 })]} />);

    expect(markerInstances).toHaveLength(0);
  });

  it('frames the whole fleet on first load', () => {
    render(<FleetMap vehicles={[vehicle(), vehicle({ vehicle_id: 8, longitude: 121.4 })]} />);

    expect(fitted?.padding).toBe(64);
  });

  it('does not re-frame when positions refresh', () => {
    // Positions arrive every minute. A view that re-fits itself each time
    // cannot be zoomed into, because the operator's camera is taken away.
    const { rerender } = render(<FleetMap vehicles={[vehicle(), vehicle({ vehicle_id: 8 })]} />);

    fitted = null;
    rerender(<FleetMap vehicles={[vehicle({ latitude: 14.6 }), vehicle({ vehicle_id: 8 })]} />);

    expect(fitted).toBeNull();
  });

  it('reports a marker click to the page', () => {
    const onSelect = vi.fn();

    render(<FleetMap vehicles={[vehicle()]} onSelect={onSelect} />);
    fireEvent.click(markerInstances[0]!.element);

    expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ vehicle_id: 7 }));
  });

  it('drops the marker of a vehicle that stops reporting', () => {
    // A device that goes quiet leaves the payload. Its marker has to go with
    // it, or the map keeps showing a vehicle at a place nobody is claiming.
    const { rerender } = render(
      <FleetMap vehicles={[vehicle(), vehicle({ vehicle_id: 8, plate_number: 'ABC1234' })]} />,
    );

    const departing = markerInstances.find((m) => m.element.textContent?.includes('ABC1234'));
    const staying = markerInstances.find((m) => m.element.textContent?.includes('NOV1616'));

    rerender(<FleetMap vehicles={[vehicle()]} />);

    expect(departing?.removed).toBe(true);
    expect(staying?.removed).toBe(false);
  });

  it('centres on a vehicle chosen from the list', () => {
    const { rerender } = render(<FleetMap vehicles={[vehicle()]} focus={null} />);

    easedTo = [];
    rerender(<FleetMap vehicles={[vehicle()]} focus={{ vehicleId: 7, at: 1 }} />);

    expect(easedTo).toContainEqual([121.0244, 14.5547]);
  });

  it('centres again when the same vehicle is asked for a second time', () => {
    // Found on production: after panning across the city, clicking the vehicle
    // already highlighted did nothing, because only a change of id moved the
    // camera — and re-clicking is exactly how an operator gets back to it.
    const { rerender } = render(
      <FleetMap vehicles={[vehicle()]} focus={{ vehicleId: 7, at: 1 }} />,
    );

    easedTo = [];
    rerender(<FleetMap vehicles={[vehicle()]} focus={{ vehicleId: 7, at: 2 }} />);

    expect(easedTo).toContainEqual([121.0244, 14.5547]);
  });

  it('does not drag the camera back on a routine refresh', () => {
    // The counterpart to the test above: positions arrive every minute, and a
    // refresh must not yank the view to whatever was last clicked.
    const focus = { vehicleId: 7, at: 1 };
    const { rerender } = render(<FleetMap vehicles={[vehicle()]} focus={focus} />);

    easedTo = [];
    rerender(<FleetMap vehicles={[vehicle({ latitude: 14.61 })]} focus={focus} />);

    expect(easedTo).toEqual([]);
  });

  it('degrades to a notice when the basemap cannot load', () => {
    // The list beside the map still answers where every vehicle was last
    // seen, so a dead basemap must not take the page down.
    mapConstructorThrows = true;
    render(<FleetMap vehicles={[vehicle()]} />);

    expect(screen.getByText(/Map temporarily unavailable/i)).toBeTruthy();
  });

  it('treats a style failure reported by MapLibre as a failure', () => {
    render(<FleetMap vehicles={[vehicle()]} />);

    expect(capturedErrorHandler).toBeTypeOf('function');

    // MapLibre invokes the handler outside React's knowledge, so the state
    // change it causes has to be flushed explicitly.
    act(() => capturedErrorHandler?.({ error: new Error('Failed to fetch style') }));

    expect(screen.getByText(/Map temporarily unavailable/i)).toBeTruthy();
  });

  it('pins MapLibre to the same-origin worker', () => {
    // The blank-basemap bug in full: without this the worker is constructed
    // from an empty URL, and every tile and glyph silently fails to parse.
    expect(workerUrls).toContain('/maplibre-gl-worker.mjs');
  });
});

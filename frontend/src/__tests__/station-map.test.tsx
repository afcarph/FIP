import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The map component's wiring.
 *
 * MapLibre needs WebGL, which jsdom has none of, so the library is mocked and
 * what is asserted is the contract this component has with it: which markers
 * get created, which coordinates are refused, that a click reaches the page,
 * and that a failing basemap degrades instead of taking the directory down.
 * Rendering pixels is a job for a browser, not this suite.
 */

const markerInstances: Array<{ lngLat: [number, number]; element: HTMLElement }> = [];
let mapConstructorThrows = false;
let capturedErrorHandler: ((event: unknown) => void) | null = null;
let sourceData: { features: Array<{ properties: { id: number } }> } | null = null;
const layerHandlers = new Map<string, (event: unknown) => void>();

vi.mock('maplibre-gl', () => {
  class Marker {
    element: HTMLElement;
    lngLat: [number, number] = [0, 0];

    constructor(options?: { element?: HTMLElement }) {
      this.element = options?.element ?? document.createElement('div');
    }

    setLngLat(value: [number, number]) {
      this.lngLat = value;

      return this;
    }

    addTo() {
      markerInstances.push({ lngLat: this.lngLat, element: this.element });

      return this;
    }

    remove() {}
  }

  class Map {
    constructor() {
      if (mapConstructorThrows) throw new Error('WebGL unavailable');
    }

    addControl() {}

    on(event: string, a?: unknown, b?: unknown) {
      if (event === 'error') capturedErrorHandler = a as (payload: unknown) => void;
      // Layer-scoped handlers arrive as (event, layerId, handler).
      if (typeof a === 'string' && typeof b === 'function') {
        layerHandlers.set(`${event}:${a}`, b as (payload: unknown) => void);
      }
      // Style load drives layer creation.
      if (event === 'load' && typeof a === 'function') (a as () => void)();
    }

    addSource(_id: string, options: { data: typeof sourceData }) {
      sourceData = options.data;
    }
    getSource() {
      return sourceData ? { setData: (d: typeof sourceData) => { sourceData = d; } } : undefined;
    }
    addLayer() {}
    getCanvas() {
      return { style: {} };
    }
    easeTo() {}
    remove() {}
  }

  return {
    Map,
    Marker,
    NavigationControl: class {},
    ScaleControl: class {},
  };
});

vi.mock('maplibre-gl/dist/maplibre-gl.css', () => ({}));

const { StationMap } = await import('@/components/map/station-map');

function station(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    name: 'Petron Ayala Avenue',
    slug: 'petron-ayala-avenue',
    brand: { id: 1, name: 'Petron', code: 'petron', color_hex: '#0033A0', logo_path: null },
    address: { line: '1 Ayala Ave', city: 'Makati', region: 'NCR', postal_code: null },
    latitude: 14.5561,
    longitude: 121.0245,
    phone: null,
    is_24_hours: true,
    has_ev_charging: false,
    status: 'active',
    is_verified: true,
    rating: { average: 4.5, count: 10 },
    ...overrides,
  } as never;
}

describe('StationMap', () => {
  beforeEach(() => {
    markerInstances.length = 0;
    mapConstructorThrows = false;
    capturedErrorHandler = null;
    sourceData = null;
    layerHandlers.clear();
  });

  it('initialises the map and renders a canvas container', () => {
    render(<StationMap stations={[station()]} />);

    expect(screen.getByTestId('maplibre-canvas')).toBeTruthy();
  });

  it('adds a clustered source containing the station', () => {
    // Stations are a GeoJSON source, not a DOM marker each: MapLibre clusters
    // sources, and a node per station stops scaling well before a national
    // directory does.
    render(<StationMap stations={[station()]} />);

    expect(sourceData?.features).toHaveLength(1);
    expect(sourceData?.features[0]?.properties.id).toBe(1);
  });

  it('skips a station with missing coordinates', () => {
    // Better absent than placed wrongly: a marker at a default position tells
    // the user this forecourt is somewhere it is not.
    render(<StationMap stations={[station({ latitude: null, longitude: null })]} />);

    expect(sourceData?.features ?? []).toHaveLength(0);
  });

  it('skips a station at null island', () => {
    render(<StationMap stations={[station({ latitude: 0, longitude: 0 })]} />);

    expect(sourceData?.features ?? []).toHaveLength(0);
  });

  it('skips a station outside the Philippines', () => {
    render(<StationMap stations={[station({ latitude: 51.5074, longitude: -0.1278 })]} />);

    expect(sourceData?.features ?? []).toHaveLength(0);
  });

  it('reports the station back when its point is clicked', () => {
    const onSelect = vi.fn();

    render(<StationMap stations={[station()]} onSelect={onSelect} />);

    layerHandlers.get('click:fip-points')?.({
      features: [{ properties: { id: 1 } }],
    });

    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect.mock.calls[0]?.[0].name).toBe('Petron Ayala Avenue');
  });

  it('zooms into a cluster instead of opening a station', () => {
    // A cluster is several stations; there is nothing single to open.
    const onSelect = vi.fn();

    render(<StationMap stations={[station()]} onSelect={onSelect} />);

    expect(layerHandlers.has('click:fip-clusters')).toBe(true);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('asks for location only when the button is pressed', () => {
    // Not on mount. A page that demands location before the user has asked
    // for anything trains them to refuse the prompt.
    const onUseMyLocation = vi.fn();

    render(<StationMap stations={[station()]} onUseMyLocation={onUseMyLocation} />);

    expect(onUseMyLocation).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: /use my location/i }));

    expect(onUseMyLocation).toHaveBeenCalledTimes(1);
  });

  it('places a marker for the user once a location is supplied', () => {
    render(
      <StationMap stations={[station()]} centre={{ latitude: 14.55, longitude: 121.02 }} />,
    );

    expect(markerInstances.some((m) => m.lngLat[0] === 121.02 && m.lngLat[1] === 14.55)).toBe(true);
  });

  it('degrades to a notice when the map cannot be constructed', () => {
    // No WebGL, or a malformed style URL.
    mapConstructorThrows = true;

    render(<StationMap stations={[station()]} />);

    expect(screen.getByText(/map temporarily unavailable/i)).toBeTruthy();
  });

  it('degrades to a notice when the tile provider rejects the style', () => {
    render(<StationMap stations={[station()]} />);

    expect(capturedErrorHandler).not.toBeNull();

    // The handler is invoked by MapLibre, outside React's knowledge, so the
    // resulting state change has to be flushed explicitly.
    act(() => capturedErrorHandler?.({ error: new Error('Failed to fetch style: 403') }));

    expect(screen.getByText(/map temporarily unavailable/i)).toBeTruthy();
  });
});

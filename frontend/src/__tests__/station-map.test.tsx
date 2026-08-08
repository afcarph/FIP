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
    on(event: string, handler: (payload: unknown) => void) {
      if (event === 'error') capturedErrorHandler = handler;
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
  });

  it('initialises the map and renders a canvas container', () => {
    render(<StationMap stations={[station()]} />);

    expect(screen.getByTestId('maplibre-canvas')).toBeTruthy();
  });

  it('creates a marker for a station with valid coordinates', () => {
    render(<StationMap stations={[station()]} />);

    expect(markerInstances).toHaveLength(1);
    expect(markerInstances[0].lngLat).toEqual([121.0245, 14.5561]);
  });

  it('skips a station with missing coordinates', () => {
    // Better absent than placed wrongly: a marker at a default position tells
    // the user this forecourt is somewhere it is not.
    render(<StationMap stations={[station({ latitude: null, longitude: null })]} />);

    expect(markerInstances).toHaveLength(0);
  });

  it('skips a station at null island', () => {
    render(<StationMap stations={[station({ latitude: 0, longitude: 0 })]} />);

    expect(markerInstances).toHaveLength(0);
  });

  it('skips a station outside the Philippines', () => {
    render(<StationMap stations={[station({ latitude: 51.5074, longitude: -0.1278 })]} />);

    expect(markerInstances).toHaveLength(0);
  });

  it('reports the station back when its marker is clicked', () => {
    const onSelect = vi.fn();

    render(<StationMap stations={[station()]} onSelect={onSelect} />);
    fireEvent.click(markerInstances[0].element);

    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect.mock.calls[0][0].name).toBe('Petron Ayala Avenue');
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

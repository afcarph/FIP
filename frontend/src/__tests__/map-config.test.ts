import { describe, expect, it } from 'vitest';

import {
  DEVELOPMENT_FALLBACK_STYLE,
  googleMapsUrl,
  hasPlottableCoordinates,
  isProviderConfigured,
  mapStyleUrl,
} from '@/lib/map-config';

/**
 * The map's provider boundary, and the two Google links.
 *
 * These are the parts that decide whether the map costs money and whether a
 * marker lands where it should. The renderer itself is exercised in the
 * component test; this covers the rules it depends on.
 */

describe('tile provider configuration', () => {
  it('falls back to a keyless development style when none is configured', () => {
    delete process.env.NEXT_PUBLIC_MAP_STYLE_URL;

    expect(mapStyleUrl()).toBe(DEVELOPMENT_FALLBACK_STYLE);
    // The UI uses this to say the basemap is a development one rather than
    // quietly presenting a world map with no streets as the product.
    expect(isProviderConfigured()).toBe(false);
  });

  it('uses the configured style url when one is set', () => {
    process.env.NEXT_PUBLIC_MAP_STYLE_URL =
      'https://api.maptiler.com/maps/streets-v2/style.json?key=test-key';

    expect(mapStyleUrl()).toContain('api.maptiler.com');
    expect(isProviderConfigured()).toBe(true);

    delete process.env.NEXT_PUBLIC_MAP_STYLE_URL;
  });

  it('treats whitespace as unset', () => {
    process.env.NEXT_PUBLIC_MAP_STYLE_URL = '   ';

    expect(isProviderConfigured()).toBe(false);
    expect(mapStyleUrl()).toBe(DEVELOPMENT_FALLBACK_STYLE);

    delete process.env.NEXT_PUBLIC_MAP_STYLE_URL;
  });

  it('never points at the public OSM tile server', () => {
    // openstreetmap.org's tiles are a volunteer-funded service with a usage
    // policy that forbids exactly this. Using them as a product backend is
    // both a breach and an outage waiting to happen.
    delete process.env.NEXT_PUBLIC_MAP_STYLE_URL;

    expect(mapStyleUrl()).not.toContain('tile.openstreetmap.org');
  });
});

describe('plottable coordinates', () => {
  it('accepts a real Philippine coordinate', () => {
    expect(hasPlottableCoordinates(14.5547, 121.0244)).toBe(true);
  });

  it('rejects missing coordinates', () => {
    expect(hasPlottableCoordinates(null, 121.0244)).toBe(false);
    expect(hasPlottableCoordinates(14.5547, undefined)).toBe(false);
  });

  it('rejects null island', () => {
    // 0,0 is what a failed import leaves behind, and it is in the Atlantic.
    expect(hasPlottableCoordinates(0, 0)).toBe(false);
  });

  it('rejects coordinates outside the Philippines', () => {
    expect(hasPlottableCoordinates(51.5074, -0.1278)).toBe(false);
    expect(hasPlottableCoordinates(35.6762, 139.6503)).toBe(false);
  });

  it('rejects non-finite values', () => {
    expect(hasPlottableCoordinates(Number.NaN, 121)).toBe(false);
    expect(hasPlottableCoordinates(14.5, Number.POSITIVE_INFINITY)).toBe(false);
  });
});

describe('google maps links', () => {
  it('builds a show-location url from coordinates alone', () => {
    const url = googleMapsUrl.showLocation(14.5561, 121.0245);

    expect(url).toBe('https://www.google.com/maps/search/?api=1&query=14.5561,121.0245');
    // A URL, not an API call: no key, no quota, nothing billable.
    expect(url).not.toContain('key=');
  });

  it('builds directions without an origin when the user location is unknown', () => {
    const url = googleMapsUrl.directions(14.5561, 121.0245, null);

    expect(url).toBe('https://www.google.com/maps/dir/?api=1&destination=14.5561,121.0245');
    expect(url).not.toContain('origin=');
  });

  it('includes the origin when the user has shared a location', () => {
    const url = googleMapsUrl.directions(14.5561, 121.0245, {
      latitude: 14.55,
      longitude: 121.02,
    });

    expect(url).toContain('destination=14.5561,121.0245');
    expect(url).toContain('origin=14.55,121.02');
  });

  it('uses no billable Google API surface', () => {
    const urls = [
      googleMapsUrl.showLocation(14, 121),
      googleMapsUrl.directions(14, 121, { latitude: 1, longitude: 2 }),
    ];

    for (const url of urls) {
      expect(url.startsWith('https://www.google.com/maps/')).toBe(true);
      expect(url).not.toContain('maps.googleapis.com');
      expect(url).not.toContain('/place/');
      expect(url).not.toContain('/directions/json');
      expect(url).not.toContain('/geocode/');
    }
  });
});

/**
 * Where the base map comes from.
 *
 * The renderer (MapLibre GL JS) and the tiles are deliberately separate
 * concerns: MapLibre consumes any style JSON, so changing provider is a URL
 * change rather than a rewrite. Nothing outside this module knows the
 * provider's name.
 *
 * MapTiler style URLs look like:
 *
 *   https://api.maptiler.com/maps/streets-v2/style.json?key=YOUR_KEY
 *
 * The key is a *client* key — it ships in the bundle by necessity, as any
 * browser map key must, and is protected by a domain allowlist in the
 * provider's dashboard rather than by secrecy. It is still read from the
 * environment so it is not committed.
 */

/**
 * MapLibre's own demo tiles. Keyless, low detail, and explicitly not for
 * production — it exists so the map renders during local development and in
 * CI before anyone has provisioned a provider key. Production sets
 * NEXT_PUBLIC_MAP_STYLE_URL; `isProviderConfigured` is what the UI uses to say
 * so, rather than silently showing a world map with no streets on it.
 */
export const DEVELOPMENT_FALLBACK_STYLE = 'https://demotiles.maplibre.org/style.json';

export function mapStyleUrl(): string {
  return process.env.NEXT_PUBLIC_MAP_STYLE_URL?.trim() || DEVELOPMENT_FALLBACK_STYLE;
}

export function isProviderConfigured(): boolean {
  return (process.env.NEXT_PUBLIC_MAP_STYLE_URL?.trim() ?? '') !== '';
}

/** Roughly the whole archipelago, for the initial view and for bounds checks. */
export const PHILIPPINES = {
  centre: { longitude: 121.774, latitude: 12.8797 },
  zoom: 5,
  bounds: { west: 116.0, east: 127.0, south: 4.5, north: 21.5 },
} as const;

/** Metro Manila, used when the user has not shared a location. */
export const DEFAULT_VIEW = {
  longitude: 121.0244,
  latitude: 14.5547,
  zoom: 12,
} as const;

/**
 * Whether a coordinate pair can be plotted.
 *
 * Rejects nulls, the 0,0 "null island" a failed import leaves behind, and
 * anything outside the Philippines — a station in the Atlantic is a data
 * error, and drawing it would stretch the map's bounds across the globe.
 */
export function hasPlottableCoordinates(
  latitude: number | null | undefined,
  longitude: number | null | undefined,
): boolean {
  if (latitude === null || latitude === undefined) return false;
  if (longitude === null || longitude === undefined) return false;
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return false;
  if (latitude === 0 && longitude === 0) return false;

  return (
    latitude >= PHILIPPINES.bounds.south &&
    latitude <= PHILIPPINES.bounds.north &&
    longitude >= PHILIPPINES.bounds.west &&
    longitude <= PHILIPPINES.bounds.east
  );
}

/** Google Maps deep links. URLs only — no API, no key, no quota. */
export const googleMapsUrl = {
  showLocation(latitude: number, longitude: number): string {
    return `https://www.google.com/maps/search/?api=1&query=${latitude},${longitude}`;
  },

  /**
   * Directions to a point, from the user's position when it is known.
   *
   * Omitting the origin lets Google use the device's own location, which is
   * better than sending a stale fix we happen to be holding.
   */
  directions(
    latitude: number,
    longitude: number,
    origin?: { latitude: number; longitude: number } | null,
  ): string {
    const base = `https://www.google.com/maps/dir/?api=1&destination=${latitude},${longitude}`;

    return origin ? `${base}&origin=${origin.latitude},${origin.longitude}` : base;
  },
};

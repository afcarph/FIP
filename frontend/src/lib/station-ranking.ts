import type { Station } from '@/types/api';

/**
 * Filtering and ranking for the map and its list.
 *
 * Pure functions over data the station API already returned. Nothing here
 * fetches: the map holds one page of stations and every filter is applied to
 * it in the browser, because a request per filter change — let alone per
 * station — is exactly the cost this architecture exists to avoid.
 *
 * The one rule that matters more than the rest: a DOE figure is the
 * department's weekly monitoring for an area and brand, never a reading taken
 * at a forecourt. It can be shown, it can be sorted on, and it must always be
 * labelled for what it is.
 */

export type PriceSource = 'station' | 'doe' | 'none';

export interface StationPricing {
  /** The figure to sort and display, or null when there is none. */
  amount: number | null;
  source: PriceSource;
  /** Only for a DOE figure: the week it describes. */
  coverageLabel?: string;
}

export interface StationFilters {
  brandId?: number;
  fuelTypeId?: number;
  radiusKm?: number;
  /** Restrict to stations carrying a safe DOE reference. */
  withDoeReference?: boolean;
  search?: string;
}

export type SortMode = 'distance' | 'price';

/**
 * What this station costs for a given fuel, and where that number came from.
 *
 * The platform's own price wins when it exists — it is a reading at the pump.
 * The DOE reference is the fallback, and is reported as such so the caller
 * cannot accidentally present it as the same kind of fact. When neither
 * exists the answer is "none", never a zero or an invented figure.
 */
export function pricingFor(station: Station, fuelTypeId?: number): StationPricing {
  const own = station.prices?.find(
    (price) => fuelTypeId === undefined || price.fuel_type_id === fuelTypeId,
  );

  if (own && Number.isFinite(own.price) && own.price > 0) {
    return { amount: own.price, source: 'station' };
  }

  const reference = station.doe_reference;

  if (reference?.matched) {
    const row = reference.prices.find(
      (price) => fuelTypeId === undefined || price.fuel_code === fuelCodeFor(fuelTypeId),
    );

    // The DOE publishes a range. The midpoint is the only single number that
    // does not favour one end, and it is labelled as a monitored figure
    // wherever it is shown.
    if (row) {
      const values = [row.min_price, row.max_price].filter(
        (value): value is number => value !== null && Number.isFinite(value),
      );

      if (values.length > 0) {
        return {
          amount: values.reduce((sum, value) => sum + value, 0) / values.length,
          source: 'doe',
          coverageLabel: reference.report?.coverage_label,
        };
      }
    }
  }

  return { amount: null, source: 'none' };
}

/**
 * The platform's fuel type id to the DOE's fuel code.
 *
 * Seeded ids, so this is a lookup rather than a guess. An id with no entry
 * returns undefined and simply matches nothing — better than falling back to
 * the first DOE row, which would quote diesel against a petrol filter.
 */
const FUEL_CODE_BY_ID: Record<number, string> = {
  1: 'gasoline_ron91',
  2: 'gasoline_ron95',
  3: 'gasoline_ron97',
  4: 'diesel',
  5: 'diesel_premium',
  6: 'kerosene',
};

export function fuelCodeFor(fuelTypeId?: number): string | undefined {
  return fuelTypeId === undefined ? undefined : FUEL_CODE_BY_ID[fuelTypeId];
}

export function hasDoeReference(station: Station): boolean {
  return station.doe_reference?.matched === true;
}

export function filterStations(stations: Station[], filters: StationFilters): Station[] {
  const needle = filters.search?.trim().toLowerCase();

  return stations.filter((station) => {
    if (filters.brandId !== undefined && station.brand?.id !== filters.brandId) return false;

    if (filters.withDoeReference && !hasDoeReference(station)) return false;

    if (filters.radiusKm !== undefined && station.distance_km !== undefined) {
      if (station.distance_km > filters.radiusKm) return false;
    }

    if (filters.fuelTypeId !== undefined) {
      // Only exclude when we can positively say this fuel is absent. A station
      // whose prices were not loaded is unknown, not empty, and hiding it
      // would silently shrink the directory.
      const known = station.prices !== undefined;
      const sells = station.prices?.some((price) => price.fuel_type_id === filters.fuelTypeId);
      const doe = pricingFor(station, filters.fuelTypeId).source === 'doe';

      if (known && !sells && !doe) return false;
    }

    if (needle) {
      const haystack = [station.name, station.brand?.name, station.address.city]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

      if (!haystack.includes(needle)) return false;
    }

    return true;
  });
}

/**
 * Rank for display.
 *
 * By price, a station with no price sorts last rather than first — a missing
 * figure is not a cheap one, and `null` compares as 0 if you let it. Distance
 * breaks ties, and stations with no distance (no user location) sort after
 * those that have one.
 */
export function sortStations(
  stations: Station[],
  mode: SortMode,
  fuelTypeId?: number,
): Station[] {
  const ranked = [...stations];

  if (mode === 'price') {
    ranked.sort((a, b) => {
      const priceA = pricingFor(a, fuelTypeId).amount;
      const priceB = pricingFor(b, fuelTypeId).amount;

      if (priceA === null && priceB === null) return distanceOf(a) - distanceOf(b);
      if (priceA === null) return 1;
      if (priceB === null) return -1;
      if (priceA !== priceB) return priceA - priceB;

      return distanceOf(a) - distanceOf(b);
    });

    return ranked;
  }

  ranked.sort((a, b) => distanceOf(a) - distanceOf(b));

  return ranked;
}

function distanceOf(station: Station): number {
  return station.distance_km ?? Number.POSITIVE_INFINITY;
}

export interface CheapestEntry {
  station: Station;
  pricing: StationPricing;
}

/**
 * The cheapest nearby stations for one fuel.
 *
 * Stations with no usable price are excluded rather than listed at the
 * bottom: a "cheapest" list is a claim about price, and an entry with no
 * price cannot support it. The caller shows "Price unavailable" against those
 * in the main list instead.
 */
export function cheapestNearby(
  stations: Station[],
  fuelTypeId: number | undefined,
  limit = 3,
): CheapestEntry[] {
  return stations
    .map((station) => ({ station, pricing: pricingFor(station, fuelTypeId) }))
    .filter((entry) => entry.pricing.amount !== null)
    .sort((a, b) => {
      const difference = (a.pricing.amount ?? 0) - (b.pricing.amount ?? 0);

      return difference !== 0 ? difference : distanceOf(a.station) - distanceOf(b.station);
    })
    .slice(0, limit);
}

/** The label a price must carry, so its provenance travels with the number. */
export function priceLabel(pricing: StationPricing): string {
  switch (pricing.source) {
    case 'station':
      return 'Station price';
    case 'doe':
      // Never "live" and never "current station price" — it is a weekly
      // area figure, and the coverage period says which week.
      return pricing.coverageLabel
        ? `DOE monitored price · ${pricing.coverageLabel}`
        : 'DOE monitored price';
    default:
      return 'Price unavailable';
  }
}

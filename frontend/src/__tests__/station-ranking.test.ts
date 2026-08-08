import { describe, expect, it } from 'vitest';

import {
  cheapestNearby,
  filterStations,
  hasDoeReference,
  priceLabel,
  pricingFor,
  sortStations,
} from '@/lib/station-ranking';
import type { Station } from '@/types/api';

/**
 * Filtering and ranking.
 *
 * The assertions that matter most are about absence: a station with no price
 * must not sort as cheap, must not appear in a "cheapest" list, and must not
 * acquire a number from somewhere else.
 */

function station(overrides: Partial<Station> = {}): Station {
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
  } as Station;
}

function doeReference(matched: boolean, min = 60, max = 62) {
  return {
    matched,
    reason: matched ? 'matched' : 'brand_not_published_in_area',
    doe_region: 'NCR',
    doe_area: 'Makati City',
    prices: matched
      ? [{ product: 'DIESEL', fuel_code: 'diesel', min_price: min, max_price: max }]
      : [],
    report: matched
      ? {
          id: 1,
          coverage_start: '2026-07-28',
          coverage_end: '2026-08-03',
          coverage_label: '28 Jul – 3 Aug 2026',
          monitoring_date: '2026-07-28',
          source_url: null,
        }
      : null,
    attribution: {
      source: 'Philippine Department of Energy',
      basis: 'Weekly area price monitoring. Not a live station price.',
    },
  } as Station['doe_reference'];
}

describe('pricing source', () => {
  it('prefers the platform’s own station price', () => {
    const result = pricingFor(
      station({
        prices: [{ fuel_type_id: 4, price: 55.5 }] as never,
        doe_reference: doeReference(true),
      }),
      4,
    );

    expect(result.source).toBe('station');
    expect(result.amount).toBe(55.5);
  });

  it('falls back to the DOE midpoint and says so', () => {
    const result = pricingFor(station({ doe_reference: doeReference(true, 60, 62) }), 4);

    expect(result.source).toBe('doe');
    expect(result.amount).toBe(61);
    expect(result.coverageLabel).toBe('28 Jul – 3 Aug 2026');
  });

  it('reports no price rather than inventing one', () => {
    const result = pricingFor(station({ doe_reference: doeReference(false) }), 4);

    expect(result.source).toBe('none');
    expect(result.amount).toBeNull();
  });

  it('ignores a zero or negative station price', () => {
    // A zero is a data defect, not free fuel.
    const result = pricingFor(station({ prices: [{ fuel_type_id: 4, price: 0 }] as never }), 4);

    expect(result.source).toBe('none');
  });
});

describe('labels', () => {
  it('never calls a DOE figure a live or current station price', () => {
    const label = priceLabel(pricingFor(station({ doe_reference: doeReference(true) }), 4));

    expect(label).toContain('DOE monitored price');
    expect(label).toContain('28 Jul – 3 Aug 2026');
    expect(label.toLowerCase()).not.toContain('live');
    expect(label.toLowerCase()).not.toContain('current station price');
  });

  it('says the price is unavailable when there is none', () => {
    expect(priceLabel({ amount: null, source: 'none' })).toBe('Price unavailable');
  });
});

describe('filters', () => {
  const stations = [
    station({ id: 1, brand: { id: 1, name: 'Petron', code: 'petron', color_hex: '', logo_path: null }, distance_km: 0.8, doe_reference: doeReference(true) }),
    station({ id: 2, name: 'Shell BGC', brand: { id: 2, name: 'Shell', code: 'shell', color_hex: '', logo_path: null }, distance_km: 3.2, doe_reference: doeReference(false) }),
    station({ id: 3, name: 'Caltex Timog', brand: { id: 3, name: 'Caltex', code: 'caltex', color_hex: '', logo_path: null }, distance_km: 12 }),
  ];

  it('filters by brand', () => {
    expect(filterStations(stations, { brandId: 2 }).map((s) => s.id)).toEqual([2]);
  });

  it('filters by radius', () => {
    expect(filterStations(stations, { radiusKm: 5 }).map((s) => s.id)).toEqual([1, 2]);
  });

  it('filters to stations carrying a DOE reference', () => {
    expect(filterStations(stations, { withDoeReference: true }).map((s) => s.id)).toEqual([1]);
  });

  it('filters by free text across name, brand and city', () => {
    expect(filterStations(stations, { search: 'shell' }).map((s) => s.id)).toEqual([2]);
    expect(filterStations(stations, { search: 'makati' }).length).toBe(3);
  });

  it('keeps a station whose prices were never loaded', () => {
    // Unknown is not empty. Excluding it would silently shrink the directory
    // whenever the caller did not ask for prices.
    expect(filterStations([station({ id: 9 })], { fuelTypeId: 4 }).map((s) => s.id)).toEqual([9]);
  });

  it('excludes a station that positively does not sell the fuel', () => {
    const noDiesel = station({ id: 10, prices: [{ fuel_type_id: 1, price: 60 }] as never });

    expect(filterStations([noDiesel], { fuelTypeId: 4 })).toHaveLength(0);
  });
});

describe('sorting', () => {
  const near = station({ id: 1, distance_km: 0.5, prices: [{ fuel_type_id: 4, price: 60 }] as never });
  const far = station({ id: 2, distance_km: 9, prices: [{ fuel_type_id: 4, price: 55 }] as never });
  const priceless = station({ id: 3, distance_km: 1 });

  it('sorts by distance', () => {
    expect(sortStations([far, near, priceless], 'distance', 4).map((s) => s.id)).toEqual([1, 3, 2]);
  });

  it('sorts by price, cheapest first', () => {
    expect(sortStations([near, far], 'price', 4).map((s) => s.id)).toEqual([2, 1]);
  });

  it('puts stations with no price last, not first', () => {
    // null compares as 0 if you let it, which would rank an unknown price as
    // the cheapest thing on the map.
    expect(sortStations([priceless, near, far], 'price', 4).map((s) => s.id)).toEqual([2, 1, 3]);
  });

  it('breaks a price tie by distance', () => {
    const a = station({ id: 4, distance_km: 5, prices: [{ fuel_type_id: 4, price: 60 }] as never });
    const b = station({ id: 5, distance_km: 2, prices: [{ fuel_type_id: 4, price: 60 }] as never });

    expect(sortStations([a, b], 'price', 4).map((s) => s.id)).toEqual([5, 4]);
  });

  it('sorts stations with no distance after those that have one', () => {
    const unknown = station({ id: 6 });
    const known = station({ id: 7, distance_km: 4 });

    expect(sortStations([unknown, known], 'distance').map((s) => s.id)).toEqual([7, 6]);
  });
});

describe('cheapest nearby', () => {
  it('ranks by price then distance and excludes stations with no price', () => {
    const list = [
      station({ id: 1, distance_km: 0.8, prices: [{ fuel_type_id: 4, price: 58 }] as never }),
      station({ id: 2, distance_km: 1.1, doe_reference: doeReference(true, 56, 58) }),
      station({ id: 3, distance_km: 1.4 }),
    ];

    const result = cheapestNearby(list, 4);

    expect(result.map((entry) => entry.station.id)).toEqual([2, 1]);
    expect(result.every((entry) => entry.pricing.amount !== null)).toBe(true);
  });

  it('returns nothing rather than a list of unknowns', () => {
    expect(cheapestNearby([station({ id: 1, distance_km: 1 })], 4)).toHaveLength(0);
  });

  it('labels a DOE entry with its monitoring period', () => {
    const [entry] = cheapestNearby(
      [station({ id: 1, distance_km: 1, doe_reference: doeReference(true) })],
      4,
    );

    expect(priceLabel(entry!.pricing)).toContain('DOE monitored price');
  });
});

describe('doe availability', () => {
  it('recognises a matched reference', () => {
    expect(hasDoeReference(station({ doe_reference: doeReference(true) }))).toBe(true);
  });

  it('does not count an unmatched reference as available', () => {
    expect(hasDoeReference(station({ doe_reference: doeReference(false) }))).toBe(false);
    expect(hasDoeReference(station())).toBe(false);
  });
});

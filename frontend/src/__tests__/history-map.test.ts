import { describe, expect, it } from 'vitest';

import { gapSegments, plottablePoints, splitIntoSegments } from '@/components/map/history-map';
import type { DeviceLocationPoint } from '@/types/api';

/**
 * What a drawn track is allowed to claim.
 *
 * The line between two fixes is an assertion about a road that was taken. When
 * the fixes are minutes apart that assertion is roughly true; when a phone
 * spent an hour underground it is fiction, drawn with exactly the same
 * confidence as the real parts. These pin where the line breaks.
 */

let id = 0;

function fix(minutesFromStart: number, overrides: Partial<DeviceLocationPoint> = {}) {
  const base = Date.UTC(2026, 7, 15, 8, 0, 0);

  return {
    id: (id += 1),
    vehicle_id: 4,
    latitude: 14.55 + minutesFromStart * 0.001,
    longitude: 121.02,
    accuracy_m: 12,
    altitude_m: null,
    speed_kph: 30,
    heading_deg: null,
    recorded_at: new Date(base + minutesFromStart * 60_000).toISOString(),
    received_at: null,
    ...overrides,
  } satisfies DeviceLocationPoint;
}

describe('drawing a track', () => {
  it('joins fixes that are close enough in time', () => {
    const segments = splitIntoSegments([fix(0), fix(2), fix(4)]);

    expect(segments).toHaveLength(1);
    expect(segments[0]?.coordinates).toHaveLength(3);
  });

  it('breaks the line where the path is unknown', () => {
    // Twenty minutes of silence in the middle. Joining these would draw a road
    // through whatever lies between, at the same weight as the measured parts.
    const segments = splitIntoSegments([fix(0), fix(2), fix(22), fix(24)]);

    expect(segments).toHaveLength(2);
    expect(segments[0]?.coordinates).toHaveLength(2);
    expect(segments[1]?.coordinates).toHaveLength(2);
  });

  it('offers the hole itself as a separate dashed run', () => {
    const gaps = gapSegments([fix(0), fix(2), fix(22)]);

    expect(gaps).toHaveLength(1);
    // It spans the two fixes either side of the silence, and nothing else.
    expect(gaps[0]?.coordinates).toHaveLength(2);
  });

  it('does not invent a gap where there is none', () => {
    expect(gapSegments([fix(0), fix(2), fix(4)])).toHaveLength(0);
  });

  it('draws no line for a single fix', () => {
    // One position is a place, not a journey.
    expect(splitIntoSegments([fix(0)])).toHaveLength(0);
  });

  it('draws no line when every fix is separated by silence', () => {
    const segments = splitIntoSegments([fix(0), fix(30), fix(60)]);

    expect(segments).toHaveLength(0);
    expect(gapSegments([fix(0), fix(30), fix(60)])).toHaveLength(2);
  });

  it('survives a fix with no timestamp rather than splitting on it', () => {
    // A missing clock is not evidence of a gap. Treating it as one would cut a
    // continuous journey in half because of one malformed row.
    const segments = splitIntoSegments([fix(0), fix(2, { recorded_at: null }), fix(4)]);

    expect(segments).toHaveLength(1);
    expect(segments[0]?.coordinates).toHaveLength(3);
  });
});

describe('which fixes can be drawn', () => {
  it('drops the 0,0 a failed import leaves behind', () => {
    const points = plottablePoints([fix(0), fix(2, { latitude: 0, longitude: 0 }), fix(4)]);

    expect(points).toHaveLength(2);
  });

  it('drops a fix outside the country rather than stretching the map to it', () => {
    const points = plottablePoints([fix(0), fix(2, { latitude: 48.85, longitude: 2.35 })]);

    expect(points).toHaveLength(1);
  });
});

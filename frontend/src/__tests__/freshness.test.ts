import { describe, expect, it } from 'vitest';

import { ageLabel, freshnessOf } from '@/components/doe/freshness';
import type { DoeReport } from '@/hooks/use-doe';

function report(coverageStart: string, coverageEnd: string): DoeReport {
  return {
    id: 1,
    region: 'NCR',
    coverage_start: coverageStart,
    coverage_end: coverageEnd,
    coverage_label: `${coverageStart} – ${coverageEnd}`,
    monitoring_date: null,
    publication_date: null,
    source_url: null,
    checksum: 'x'.repeat(64),
    extractor: 'pdfplumber-coordinates',
    quality: 1,
    areas_count: 12,
    rows_count: 363,
  };
}

function expectFreshness(entry: DoeReport, now: Date) {
  const result = freshnessOf(entry, now);

  if (result === null) throw new Error('expected a readable coverage date');

  return result;
}

// Friday, inside the 4–10 Aug week.
const NOW = new Date(2026, 7, 7);

describe('freshnessOf', () => {
  it('treats the week in progress as current rather than aged', () => {
    const result = expectFreshness(report('2026-08-04', '2026-08-10'), NOW);

    expect(result.isRunningWeek).toBe(true);
    expect(result.ageDays).toBe(0);
    expect(result.status).toBe('current');
    expect(ageLabel(result)).toBe('Current week');
  });

  it('counts age from the end of the covered week', () => {
    const result = expectFreshness(report('2026-07-28', '2026-08-03'), NOW);

    expect(result.ageDays).toBe(4);
    expect(ageLabel(result)).toBe('4 days');
  });

  it('still calls last week current, because this week may not be published yet', () => {
    // The DOE posts the running week partway through it. A region holding only
    // the previous week is waiting, not stale — calling it stale would cry wolf
    // every Tuesday morning.
    expect(expectFreshness(report('2026-07-28', '2026-08-03'), NOW).status).toBe('current');
  });

  it('flags a region that has missed a publication', () => {
    const result = expectFreshness(report('2026-07-21', '2026-07-27'), NOW);

    expect(result.ageDays).toBe(11);
    expect(result.status).toBe('stale');
  });

  it('flags a region that has missed two', () => {
    expect(expectFreshness(report('2026-07-07', '2026-07-13'), NOW).status).toBe('behind');
  });

  it('does not shift a day across the midnight boundary', () => {
    // Plain dates parsed as UTC instants land on the previous evening in
    // Manila, which moved every age by a day depending on the hour of the run.
    const lateEvening = new Date(2026, 7, 7, 23, 59);
    const earlyMorning = new Date(2026, 7, 7, 0, 1);

    expect(expectFreshness(report('2026-07-28', '2026-08-03'), lateEvening).ageDays).toBe(
      expectFreshness(report('2026-07-28', '2026-08-03'), earlyMorning).ageDays,
    );
  });

  it('uses the singular for one day', () => {
    expect(ageLabel(expectFreshness(report('2026-07-29', '2026-08-06'), NOW))).toBe('1 day');
  });

  it('returns null for a coverage date it cannot read', () => {
    // Better an honest gap than an age of 20,000 days from an epoch fallback.
    expect(freshnessOf(report('2026-08-04', 'not-a-date'), NOW)).toBeNull();
  });
});

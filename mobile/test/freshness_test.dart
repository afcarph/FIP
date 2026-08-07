import 'package:flutter_test/flutter_test.dart';

import 'package:fip_mobile/features/doe/data/doe_models.dart';

DoeReport report(String coverageStart, String coverageEnd) => DoeReport(
  id: 1,
  region: 'NCR',
  coverageStart: coverageStart,
  coverageEnd: coverageEnd,
  coverageLabel: '$coverageStart – $coverageEnd',
  areasCount: 12,
  rowsCount: 363,
);

/// Friday, inside the 4–10 Aug week.
final now = DateTime(2026, 8, 7);

void main() {
  group('Freshness', () {
    test('treats the week in progress as current rather than aged', () {
      final result = Freshness.of(report('2026-08-04', '2026-08-10'), now: now);

      expect(result.isRunningWeek, isTrue);
      expect(result.ageDays, 0);
      expect(result.status, FreshnessStatus.current);
      expect(result.ageLabel, 'Current week');
    });

    test('counts age from the end of the covered week', () {
      final result = Freshness.of(report('2026-07-28', '2026-08-03'), now: now);

      expect(result.ageDays, 4);
      expect(result.ageLabel, '4 days');
    });

    test('still calls last week current, because this week may not be published yet', () {
      // The DOE posts the running week partway through it. A region holding
      // only the previous week is waiting, not stale — calling it stale would
      // cry wolf every Tuesday morning.
      expect(
        Freshness.of(report('2026-07-28', '2026-08-03'), now: now).status,
        FreshnessStatus.current,
      );
    });

    test('flags a region that has missed a publication', () {
      final result = Freshness.of(report('2026-07-21', '2026-07-27'), now: now);

      expect(result.ageDays, 11);
      expect(result.status, FreshnessStatus.stale);
    });

    test('flags a region that has missed two', () {
      expect(
        Freshness.of(report('2026-07-07', '2026-07-13'), now: now).status,
        FreshnessStatus.behind,
      );
    });

    test('does not shift a day across the midnight boundary', () {
      // Plain dates compared as instants moved every age by a day depending on
      // the hour the app happened to be opened.
      final lateEvening = DateTime(2026, 8, 7, 23, 59);
      final earlyMorning = DateTime(2026, 8, 7, 0, 1);

      expect(
        Freshness.of(report('2026-07-28', '2026-08-03'), now: lateEvening).ageDays,
        Freshness.of(report('2026-07-28', '2026-08-03'), now: earlyMorning).ageDays,
      );
    });

    test('uses the singular for one day', () {
      expect(Freshness.of(report('2026-07-29', '2026-08-06'), now: now).ageLabel, '1 day');
    });
  });
}

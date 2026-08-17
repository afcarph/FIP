import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';

/// Diagnostic: does the 50 m distance filter explain the pilot's long gaps?
///
/// The coordinates below are the eight genuine positions device 8 reported on
/// 2026-08-09. Nothing is invented and no movement is simulated — these are
/// replayed from the database so the filter's decision can be checked against
/// what actually happened.
///
/// `Geolocator.distanceBetween` is a pure calculation with no platform channel,
/// so the same arithmetic `TrackingService._sample` performs can be exercised
/// directly.
///
/// The finding is in the assertions: every consecutive movement is under 7 m,
/// far below the 50 m threshold. So the filter *would* have suppressed each of
/// these samples — yet all eight were stored. That rules out the filter as the
/// explanation for the gaps, and points instead at `_lastSampled` being null
/// each time, which happens when the service instance is fresh.
void main() {
  const positions = [
    (time: '13:07:09', lat: 14.7958015, lon: 121.0166619),
    (time: '13:16:52', lat: 14.7958015, lon: 121.0166619),
    (time: '13:22:30', lat: 14.7957673, lon: 121.0166128),
    (time: '14:00:47', lat: 14.7957717, lon: 121.0166061),
    (time: '14:15:32', lat: 14.7957757, lon: 121.0166140),
    (time: '14:46:08', lat: 14.7957758, lon: 121.0166139),
    (time: '15:18:21', lat: 14.7957742, lon: 121.0166190),
    (time: '15:26:27', lat: 14.7957746, lon: 121.0166176),
  ];

  const minimumDistanceMetres = 50.0;

  double movement(int from, int to) => Geolocator.distanceBetween(
    positions[from].lat,
    positions[from].lon,
    positions[to].lat,
    positions[to].lon,
  );

  test('every consecutive movement is far below the filter threshold', () {
    for (var i = 1; i < positions.length; i++) {
      expect(
        movement(i - 1, i),
        lessThan(minimumDistanceMetres),
        reason: 'position $i moved enough to pass the filter, which was not expected',
      );
    }
  });

  test('the device never moved more than seven metres in total', () {
    // A stationary phone, not a vehicle on a route.
    for (var i = 1; i < positions.length; i++) {
      expect(movement(0, i), lessThan(7.0));
    }
  });

  test('the filter would have suppressed all but the first sample', () {
    // Replays the exact rule from TrackingService._sample: a sample is queued
    // only when it is at least `minimumDistanceMetres` from the last *queued*
    // position — the previous sample is not updated when the filter rejects.
    var queued = 1;
    var lastQueued = 0;

    for (var i = 1; i < positions.length; i++) {
      if (movement(lastQueued, i) >= minimumDistanceMetres) {
        queued++;
        lastQueued = i;
      }
    }

    expect(
      queued,
      1,
      reason:
          'With one continuous TrackingService instance the filter yields a single row. '
          'Eight were stored, so each was a first sample from a fresh instance — the '
          'app being reopened, not tracking failing.',
    );
  });

  test('a genuine vehicle movement passes the filter', () {
    // ~90 m apart: proves the threshold is not simply rejecting everything.
    final moved = Geolocator.distanceBetween(14.7958015, 121.0166619, 14.7966, 121.0170);

    expect(moved, greaterThan(minimumDistanceMetres));
  });
}

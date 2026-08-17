import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:fip_mobile/features/tracking/data/location_queue.dart';

/// The queue is what makes a tunnel cost latency rather than data, so the
/// behaviour worth pinning is what happens when things go wrong: a long
/// outage, a partial upload, and storage that has been corrupted.
void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  Map<String, dynamic> point(String recordedAt) => {
    'latitude': 14.5995,
    'longitude': 120.9842,
    'recorded_at': recordedAt,
  };

  test('positions survive being written and read back', () async {
    final queue = LocationQueue();

    await queue.add(point('2026-08-09T01:00:00Z'));
    await queue.add(point('2026-08-09T01:02:00Z'));

    final stored = await queue.read();

    expect(stored, hasLength(2));
    expect(stored.first['recorded_at'], '2026-08-09T01:00:00Z');
  });

  test('the queue is bounded, discarding the oldest', () async {
    // A phone offline for a week must not grow an unbounded queue until the
    // write fails. Losing the oldest is the right trade: the newest positions
    // are the ones anyone is waiting to see.
    final queue = LocationQueue(maxEntries: 3);

    for (var i = 0; i < 6; i++) {
      await queue.add(point('2026-08-09T01:0$i:00Z'));
    }

    final stored = await queue.read();

    expect(stored, hasLength(3));
    expect(stored.first['recorded_at'], '2026-08-09T01:03:00Z');
    expect(stored.last['recorded_at'], '2026-08-09T01:05:00Z');
  });

  test('only the points the server accepted are removed', () async {
    // A flush runs while sampling continues, so trimming by count would
    // discard positions that were never sent.
    final queue = LocationQueue();

    await queue.add(point('2026-08-09T01:00:00Z'));
    await queue.add(point('2026-08-09T01:01:00Z'));

    final sent = ['2026-08-09T01:00:00Z'];

    await queue.add(point('2026-08-09T01:02:00Z')); // arrived mid-flight
    await queue.removeRecorded(sent);

    final remaining = (await queue.read()).map((p) => p['recorded_at'] as String).toList();

    expect(remaining, ['2026-08-09T01:01:00Z', '2026-08-09T01:02:00Z']);
  });

  test('corrupt storage is discarded rather than thrown', () async {
    SharedPreferences.setMockInitialValues({'fip.location_queue': 'not json at all'});

    // A queue that cannot be parsed cannot be uploaded either, so failing here
    // would strand the feature with no way back.
    expect(await LocationQueue().read(), isEmpty);
  });

  test('clearing empties the queue', () async {
    final queue = LocationQueue();

    await queue.add(point('2026-08-09T01:00:00Z'));
    await queue.clear();

    expect(await queue.length(), 0);
  });

  test('stored entries are valid JSON the API can post directly', () async {
    final queue = LocationQueue();

    await queue.add(point('2026-08-09T01:00:00Z'));

    final prefs = await SharedPreferences.getInstance();
    final decoded = jsonDecode(prefs.getString('fip.location_queue')!);

    expect(decoded, isA<List<dynamic>>());
    expect((decoded as List).first['latitude'], 14.5995);
  });
}

import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// A bounded, persistent queue of positions waiting to be uploaded.
///
/// A vehicle loses signal in tunnels, basements and most of the countryside,
/// so positions have to survive both the outage and the app being killed
/// during it. SharedPreferences rather than a database: this is a short list of
/// small maps with no querying, and adding sqflite to store it would be a
/// dependency bought for nothing.
///
/// The bound is the important part. An unbounded queue on a phone that has been
/// offline for a week is a slow memory leak that ends in a failed write, so the
/// oldest entries are dropped once the cap is reached. Losing the oldest
/// positions is the right trade: the newest are what the operator is waiting to
/// see, and the server prunes old history anyway.
class LocationQueue {
  LocationQueue({this.maxEntries = 500});

  static const _storageKey = 'fip.location_queue';

  /// Roughly a day of sampling at the default interval. Past this the oldest
  /// entries are discarded rather than the newest refused.
  final int maxEntries;

  Future<List<Map<String, dynamic>>> read() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_storageKey);

    if (raw == null || raw.isEmpty) return [];

    try {
      final decoded = jsonDecode(raw);

      return decoded is List
          ? decoded.whereType<Map<String, dynamic>>().toList()
          : <Map<String, dynamic>>[];
    } catch (_) {
      // Corrupt storage is not worth failing over, and a queue that cannot be
      // parsed cannot be uploaded either. Drop it and carry on.
      await prefs.remove(_storageKey);

      return [];
    }
  }

  Future<void> add(Map<String, dynamic> point) async {
    final queue =
        await read()
          ..add(point);

    await _write(queue);
  }

  /// Remove points that the server has accepted, matched on their timestamp.
  ///
  /// Matched rather than "drop the first N": a flush runs while sampling
  /// continues, so by the time the response arrives the queue may have grown,
  /// and trimming by count would discard positions that were never sent.
  Future<void> removeRecorded(Iterable<String> recordedAt) async {
    final sent = recordedAt.toSet();
    final queue = await read();

    await _write(queue.where((point) => !sent.contains(point['recorded_at'])).toList());
  }

  Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();

    await prefs.remove(_storageKey);
  }

  Future<int> length() async => (await read()).length;

  Future<void> _write(List<Map<String, dynamic>> queue) async {
    final prefs = await SharedPreferences.getInstance();

    // Keep the newest when over the cap: the tail is what anyone is waiting for.
    final trimmed = queue.length > maxEntries ? queue.sublist(queue.length - maxEntries) : queue;

    await prefs.setString(_storageKey, jsonEncode(trimmed));
  }
}

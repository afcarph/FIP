import 'package:battery_plus/battery_plus.dart';

import '../../../core/network/api_client.dart';

/// Reports this device's battery so a fleet operator can see why a vehicle
/// went quiet.
///
/// A vehicle is only visible on the map while the handset in it is awake and
/// charged, so "the phone is on 6% and falling" is the difference between a
/// van nobody can find and a van whose driver needs a charger.
///
/// Deliberately separate from TrackingService rather than folded into it. The
/// location path has a movement filter, a queue and a privacy promise attached
/// to it, and none of that should acquire a second reason to change. This
/// class holds no timer of its own: TrackingService calls it on the tick it
/// already has, so nothing here alters the sampling interval or the filter.
///
/// Needs no permission on either platform, starts no background work, and
/// reads a value the OS already publishes to every app.
class DeviceHealthReporter {
  DeviceHealthReporter({required ApiClient api, Battery? battery})
    : _api = api,
      _battery = battery ?? Battery();

  final ApiClient _api;
  final Battery _battery;

  /// Resend an unchanged reading no more often than this.
  ///
  /// Without a floor, a phone sitting at the same charge would write a row
  /// every interval to say nothing had happened. With it, a parked device
  /// still proves it is alive — which is exactly what the offline view needs —
  /// without a request per tick.
  static const Duration _repeatAfter = Duration(minutes: 10);

  int? _lastPercentage;
  String? _lastState;
  DateTime? _lastReportedAt;

  /// Read the battery and report it, if there is anything worth saying.
  ///
  /// Never throws. A failed health report must not disturb location: the
  /// vehicle's position is the product, and its battery is context.
  Future<void> report({DateTime? now}) async {
    final at = now ?? DateTime.now();

    try {
      final percentage = await _battery.batteryLevel;
      final state = _describe(await _battery.batteryState);

      if (!_isWorthSending(percentage, state, at)) return;

      // Clamped rather than trusted. iOS returns -1 when the level is
      // unavailable, and the server refuses anything outside 0-100 — sending
      // it would cost a 422 and tell the operator nothing.
      if (percentage < 0 || percentage > 100) return;

      await _api.post<Map<String, dynamic>>(
        '/devices/health',
        body: {
          'battery_percentage': percentage,
          'battery_state': state,
          'recorded_at': at.toUtc().toIso8601String(),
        },
      );

      _lastPercentage = percentage;
      _lastState = state;
      _lastReportedAt = at;
    } catch (_) {
      // Offline, unregistered, or revoked. The next tick tries again, and
      // nothing about tracking depends on this having succeeded.
    }
  }

  bool _isWorthSending(int percentage, String state, DateTime at) {
    if (_lastReportedAt == null) return true;

    // A state change is always news: a driver plugging in is the answer to
    // the low-battery warning somebody is looking at.
    if (state != _lastState) return true;
    if (percentage != _lastPercentage) return true;

    return at.difference(_lastReportedAt!) >= _repeatAfter;
  }

  /// The platforms do not agree on what states exist, so this maps to the set
  /// the server accepts. `unknown` is reported rather than swallowed: "the
  /// device could not tell" and "the device never said" are different facts,
  /// and only the first one proves the app is running.
  String _describe(BatteryState state) => switch (state) {
    BatteryState.charging => 'charging',
    BatteryState.discharging => 'discharging',
    BatteryState.full => 'full',
    _ => 'unknown',
  };

  /// Forget what was last sent, so the next report is unconditional.
  ///
  /// Called when tracking restarts: a device that has been away should say
  /// where it stands rather than stay silent because the charge happens to
  /// match what it reported before.
  void reset() {
    _lastPercentage = null;
    _lastState = null;
    _lastReportedAt = null;
  }
}

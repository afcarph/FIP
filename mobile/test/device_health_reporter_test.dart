import 'package:battery_plus/battery_plus.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:fip_mobile/core/network/api_client.dart';
import 'package:fip_mobile/features/tracking/data/device_health_reporter.dart';

/// A battery whose readings the test controls.
class _FakeBattery implements Battery {
  _FakeBattery(this.level, this.state);

  int level;
  BatteryState state;

  @override
  Future<int> get batteryLevel async => level;

  @override
  Future<BatteryState> get batteryState async => state;

  @override
  Future<bool> get isInBatterySaveMode async => false;

  @override
  Stream<BatteryState> get onBatteryStateChanged => const Stream.empty();
}

/// Records what was posted instead of sending it.
class _RecordingApi extends ApiClient {
  _RecordingApi() : super(baseUrl: 'http://localhost/api/v1');

  final List<Map<String, dynamic>> posted = [];
  bool shouldFail = false;

  @override
  Future<T> post<T>(String path, {Object? body, bool skipAuth = false}) async {
    if (shouldFail) throw Exception('offline');

    posted.add({'path': path, 'body': body});

    return <String, dynamic>{} as T;
  }
}

void main() {
  late _RecordingApi api;
  late _FakeBattery battery;
  late DeviceHealthReporter reporter;

  setUp(() {
    api = _RecordingApi();
    battery = _FakeBattery(80, BatteryState.discharging);
    reporter = DeviceHealthReporter(api: api, battery: battery);
  });

  test('the first reading is always sent', () async {
    await reporter.report();

    expect(api.posted, hasLength(1));
    expect(api.posted.first['path'], '/devices/health');

    final body = api.posted.first['body'] as Map<String, dynamic>;
    expect(body['battery_percentage'], 80);
    expect(body['battery_state'], 'discharging');
  });

  test('an unchanged reading is not resent every tick', () async {
    // A phone sitting at the same charge would otherwise write a row every
    // interval to say that nothing had happened.
    final start = DateTime(2026, 8, 14, 9);

    await reporter.report(now: start);
    await reporter.report(now: start.add(const Duration(minutes: 2)));
    await reporter.report(now: start.add(const Duration(minutes: 4)));

    expect(api.posted, hasLength(1));
  });

  test('an unchanged reading is resent once it goes stale', () async {
    // A parked device still has to prove it is alive, or the offline view
    // cannot tell "quiet" from "gone".
    final start = DateTime(2026, 8, 14, 9);

    await reporter.report(now: start);
    await reporter.report(now: start.add(const Duration(minutes: 11)));

    expect(api.posted, hasLength(2));
  });

  test('plugging in is reported immediately', () async {
    // The answer to a low-battery warning somebody is looking at right now.
    final start = DateTime(2026, 8, 14, 9);

    await reporter.report(now: start);
    battery.state = BatteryState.charging;
    await reporter.report(now: start.add(const Duration(seconds: 30)));

    expect(api.posted, hasLength(2));
    expect((api.posted.last['body'] as Map<String, dynamic>)['battery_state'], 'charging');
  });

  test('a change in charge is reported immediately', () async {
    final start = DateTime(2026, 8, 14, 9);

    await reporter.report(now: start);
    battery.level = 79;
    await reporter.report(now: start.add(const Duration(seconds: 30)));

    expect(api.posted, hasLength(2));
  });

  test('an unreadable level is not sent', () async {
    // iOS returns -1 when the level is unavailable. The server refuses
    // anything outside 0-100, so sending it costs a 422 and says nothing.
    battery.level = -1;

    await reporter.report();

    expect(api.posted, isEmpty);
  });

  test('a full battery maps to its own state rather than to charging', () async {
    battery.state = BatteryState.full;

    await reporter.report();

    expect((api.posted.single['body'] as Map<String, dynamic>)['battery_state'], 'full');
  });

  test('an unknown platform state is reported as unknown', () async {
    // "The device could not tell" still proves the app is running, which is
    // more than silence does.
    battery.state = BatteryState.unknown;

    await reporter.report();

    expect((api.posted.single['body'] as Map<String, dynamic>)['battery_state'], 'unknown');
  });

  test('a failed report never throws', () async {
    // Location is the product; battery is context. A health failure must not
    // be able to disturb the tick that samples position.
    api.shouldFail = true;

    await expectLater(reporter.report(), completes);
  });

  test('a failed report is retried rather than treated as sent', () async {
    final start = DateTime(2026, 8, 14, 9);
    api.shouldFail = true;
    await reporter.report(now: start);

    api.shouldFail = false;
    await reporter.report(now: start.add(const Duration(seconds: 30)));

    expect(api.posted, hasLength(1));
  });

  test('reset makes the next report unconditional', () async {
    final start = DateTime(2026, 8, 14, 9);

    await reporter.report(now: start);
    reporter.reset();
    await reporter.report(now: start.add(const Duration(seconds: 5)));

    expect(api.posted, hasLength(2));
  });
}

import 'package:flutter_test/flutter_test.dart';

import 'package:fip_mobile/core/theme/fip_brand.dart';
import 'package:fip_mobile/features/fleet/data/fleet_models.dart';

/// Fleet Pulse is the number an operator acts on, so the rules that produce it
/// are worth pinning: what counts as reporting, what counts as moving, and
/// what earns a vehicle a place in "attention".
void main() {
  FleetVehicle vehicle({
    int id = 1,
    double? fuel,
    Duration? since,
    bool alert = false,
  }) {
    return FleetVehicle(
      id: id,
      plateNumber: 'ABC $id',
      fuelPercentage: fuel,
      hasOpenAlert: alert,
      locationRecordedAt: since == null ? null : DateTime.now().subtract(since),
    );
  }

  group('status', () {
    test('a vehicle reporting within the sampling window is moving', () {
      expect(vehicle(since: const Duration(minutes: 3)).status, FleetStatus.moving);
    });

    test('a recent but not live position reads as stopped', () {
      // Ten minutes is the cutoff: beyond it the position is last-known, and
      // calling that "moving" would overstate what the server actually knows.
      expect(vehicle(since: const Duration(minutes: 40)).status, FleetStatus.stopped);
    });

    test('a vehicle out of contact is offline, not an alert', () {
      final v = vehicle(since: const Duration(hours: 5));

      expect(v.status, FleetStatus.offline);
      expect(v.isReporting, isFalse);
    });

    test('a vehicle that never reported is offline rather than crashing', () {
      expect(vehicle().status, FleetStatus.offline);
      expect(vehicle().lastSeenLabel, 'Never');
    });

    test('an open alert outranks any movement state', () {
      expect(vehicle(since: const Duration(minutes: 1), alert: true).status, FleetStatus.attention);
    });

    test('a critically low tank raises attention on its own', () {
      expect(vehicle(since: const Duration(minutes: 1), fuel: 8).status, FleetStatus.attention);
    });

    test('a healthy tank does not raise attention', () {
      expect(vehicle(since: const Duration(minutes: 1), fuel: 60).status, FleetStatus.moving);
    });

    test('an unmeasured tank is not treated as empty', () {
      // The commonest way to get this wrong is to default a null reading to
      // zero, which would flag every unmeasured vehicle as critical.
      expect(vehicle(since: const Duration(minutes: 1), fuel: null).status, FleetStatus.moving);
    });
  });

  group('pulse', () {
    test('counts each vehicle into exactly one state', () {
      final pulse = FleetPulseData.from([
        vehicle(id: 1, since: const Duration(minutes: 2)),
        vehicle(id: 2, since: const Duration(minutes: 2)),
        vehicle(id: 3, since: const Duration(minutes: 45)),
        vehicle(id: 4, since: const Duration(hours: 9)),
        vehicle(id: 5, since: const Duration(minutes: 1), alert: true),
      ]);

      expect(pulse.total, 5);
      expect(pulse.moving, 2);
      expect(pulse.stopped, 1);
      expect(pulse.attention, 1);
      expect(pulse.moving + pulse.stopped + pulse.attention, lessThanOrEqualTo(pulse.total));
    });

    test('active counts vehicles in contact, including those needing attention', () {
      final pulse = FleetPulseData.from([
        vehicle(id: 1, since: const Duration(minutes: 5)),
        vehicle(id: 2, since: const Duration(minutes: 5), alert: true),
        vehicle(id: 3, since: const Duration(hours: 6)),
      ]);

      expect(pulse.active, 2, reason: 'the offline vehicle is not reporting');
    });

    test('an empty fleet produces zeroes rather than throwing', () {
      final pulse = FleetPulseData.from([]);

      expect(pulse.total, 0);
      expect(pulse.active, 0);
      expect(pulse.attention, 0);
    });
  });

  group('alerts', () {
    test('alert types are given operator wording', () {
      const alert = FleetAlert(id: 1, type: 'fuel_loss', severity: FipSeverity.critical);

      expect(alert.title, 'Possible fuel loss');
    });

    test('an unknown type degrades to something readable', () {
      const alert = FleetAlert(id: 1, type: 'some_new_type', severity: FipSeverity.low);

      expect(alert.title, 'some new type');
    });

    test('severity parses defensively', () {
      expect(FipSeverityStyle.parse('CRITICAL'), FipSeverity.critical);
      expect(FipSeverityStyle.parse(null), FipSeverity.low);
      expect(FipSeverityStyle.parse('nonsense'), FipSeverity.low);
    });
  });
}

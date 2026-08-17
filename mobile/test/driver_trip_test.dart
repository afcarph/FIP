import 'package:flutter_test/flutter_test.dart';

import 'package:fip_mobile/features/driver/data/trip_providers.dart';

/// What the driver's trip card is allowed to offer.
///
/// None of this is authorisation — the server decides that, and refuses
/// anything it disagrees with. What these pin is that the card reads the
/// server's own `can` list instead of reimplementing the state machine, and
/// that a finished trip stops occupying a driver's home screen.
void main() {
  Map<String, dynamic> payload({
    String status = 'dispatched',
    List<String> can = const ['in_progress', 'cancelled'],
    Map<String, dynamic>? vehicle,
    Map<String, dynamic>? odometer,
  }) => {
    'id': 7,
    'reference_no': 'TRP-2026-00007',
    'status': status,
    'can': can,
    'origin': 'Alabang',
    'destination': 'Sta. Rosa',
    'purpose': 'Delivery',
    'vehicle': vehicle ?? {'id': 3, 'plate_number': 'NA3950'},
    'odometer': odometer ?? {'start': null, 'end': null},
    'timeline': const {'scheduled_for': null},
  };

  group('reading a trip', () {
    test('it takes its fields from the payload', () {
      final trip = DriverTrip.fromJson(payload());

      expect(trip.id, 7);
      expect(trip.reference, 'TRP-2026-00007');
      expect(trip.route, 'Alabang → Sta. Rosa');
      expect(trip.plateNumber, 'NA3950');
    });

    test('a missing reference falls back to the id rather than showing null', () {
      final raw = payload()..remove('reference_no');

      expect(DriverTrip.fromJson(raw).reference, 'Trip 7');
    });

    test('a trip with no vehicle loaded does not crash the card', () {
      final raw = payload()..['vehicle'] = null;

      expect(DriverTrip.fromJson(raw).plateNumber, isNull);
    });
  });

  group('what the driver may do', () {
    test('a dispatched trip offers only starting', () {
      final trip = DriverTrip.fromJson(payload(can: ['in_progress', 'cancelled']));

      expect(trip.canStart, isTrue);
      expect(trip.canComplete, isFalse);
    });

    test('a trip under way offers only completing', () {
      final trip = DriverTrip.fromJson(
        payload(status: 'in_progress', can: ['completed']),
      );

      expect(trip.canStart, isFalse);
      expect(trip.canComplete, isTrue);
    });

    test('a terminal trip offers nothing', () {
      final trip = DriverTrip.fromJson(payload(status: 'completed', can: const []));

      expect(trip.canStart, isFalse);
      expect(trip.canComplete, isFalse);
    });

    test('the actions come from the server, not from the status', () {
      // If the API ever stops allowing a transition, the card stops offering
      // it — without this file being touched. That is the point of reading
      // `can` rather than switching on the status.
      final trip = DriverTrip.fromJson(payload(status: 'dispatched', can: const []));

      expect(trip.canStart, isFalse);
    });
  });

  group('what belongs on the home screen', () {
    test('dispatched and in-progress trips are live', () {
      expect(DriverTrip.fromJson(payload(status: 'dispatched')).isLive, isTrue);
      expect(DriverTrip.fromJson(payload(status: 'in_progress')).isLive, isTrue);
    });

    test('finished and abandoned trips are not', () {
      // History belongs in the fleet office, not on the screen of somebody
      // deciding what to do next.
      expect(DriverTrip.fromJson(payload(status: 'completed')).isLive, isFalse);
      expect(DriverTrip.fromJson(payload(status: 'cancelled')).isLive, isFalse);
    });

    test('a draft is not live either — it has not been sent out', () {
      expect(DriverTrip.fromJson(payload(status: 'draft')).isLive, isFalse);
    });
  });

  group('status wording', () {
    test('it reads as an instruction rather than a column value', () {
      expect(DriverTrip.fromJson(payload(status: 'dispatched')).statusLabel, 'Ready to start');
      expect(DriverTrip.fromJson(payload(status: 'in_progress')).statusLabel, 'On the road');
    });

    test('an unknown status degrades to something readable', () {
      expect(DriverTrip.fromJson(payload(status: 'on_hold')).statusLabel, 'on hold');
    });
  });
}

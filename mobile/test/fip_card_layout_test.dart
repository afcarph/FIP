import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:fip_mobile/core/theme/fip_brand.dart';
import 'package:fip_mobile/features/fleet/data/fleet_models.dart';
import 'package:fip_mobile/shared/widgets/fip/alert_card.dart';
import 'package:fip_mobile/shared/widgets/fip/fip_chrome.dart';
import 'package:fip_mobile/shared/widgets/fip/fleet_pulse.dart';
import 'package:fip_mobile/shared/widgets/fip/fuel_card.dart';
import 'package:fip_mobile/shared/widgets/fip/metric_card.dart';
import 'package:fip_mobile/shared/widgets/fip/vehicle_card.dart';

/// Every FIP card renders inside a scroll view, where height is unbounded.
///
/// FipCard originally drew its status accent as a stretched Row child, which
/// needs a bounded height. In a sliver it threw "BoxConstraints forces an
/// infinite height" — and because the exception killed the sliver, every
/// section below it vanished too. Home lost its metrics, alerts and vehicle
/// list; Fleet rendered one card of five. These tests exist so that failure
/// mode cannot come back silently.
void main() {
  Future<void> pump(WidgetTester tester, Widget child) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ListView(children: [child]),
        ),
      ),
    );
  }

  final vehicle = FleetVehicle(
    id: 1,
    plateNumber: 'MH 3001',
    displayName: 'Toyota Hilux',
    efficiency: 10.2,
    locationRecordedAt: DateTime.now(),
  );

  testWidgets('MetricCard survives unbounded height in a Row', (tester) async {
    await pump(
      tester,
      const Row(
        children: [
          Expanded(child: MetricCard(label: 'Average tank', value: '52', unit: '%')),
          SizedBox(width: 12),
          Expanded(child: MetricCard(label: 'Efficiency', value: '10.2', unit: 'km/L')),
        ],
      ),
    );

    expect(tester.takeException(), isNull);
  });

  testWidgets('VehicleCard renders without an accent', (tester) async {
    await pump(tester, VehicleCard(vehicle: vehicle));

    expect(tester.takeException(), isNull);
    expect(find.text('MH 3001'), findsOneWidget);
  });

  testWidgets('VehicleCard renders with an accent', (tester) async {
    // The accented path is the one that used to throw, so it is tested
    // separately rather than assumed equivalent.
    await pump(tester, VehicleCard(vehicle: vehicle.copyWith(hasOpenAlert: true)));

    expect(tester.takeException(), isNull);
  });

  testWidgets('AlertCard always carries an accent and still renders', (tester) async {
    await pump(
      tester,
      const AlertCard(
        alert: FleetAlert(id: 1, type: 'fuel_loss', severity: FipSeverity.critical),
      ),
    );

    expect(tester.takeException(), isNull);
    expect(find.text('Possible fuel loss'), findsOneWidget);
  });

  testWidgets('FuelCard renders with and without a reading', (tester) async {
    await pump(tester, const FuelCard(percentage: null));
    expect(tester.takeException(), isNull);

    await pump(tester, const FuelCard(percentage: 42, litres: 21, capacity: 50));
    expect(tester.takeException(), isNull);
  });

  testWidgets('FleetPulse renders', (tester) async {
    await pump(
      tester,
      const FleetPulse(active: 0, moving: 0, stopped: 0, attention: 1, total: 5),
    );

    expect(tester.takeException(), isNull);
    expect(find.text('FLEET PULSE'), findsOneWidget);
    expect(find.textContaining('needs attention'), findsOneWidget);
  });

  testWidgets('a full column of mixed cards renders together', (tester) async {
    // The real failure only showed up with several cards stacked, because the
    // first one rendered before the exception took out the rest.
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ListView(
            children: [
              const FleetPulse(active: 0, moving: 0, stopped: 0, attention: 1, total: 5),
              const Row(
                children: [
                  Expanded(child: MetricCard(label: 'Tank', value: '52', unit: '%')),
                  Expanded(child: MetricCard(label: 'Efficiency', value: '9.9')),
                ],
              ),
              VehicleCard(vehicle: vehicle),
              VehicleCard(vehicle: vehicle.copyWith(hasOpenAlert: true)),
              const AlertCard(
                alert: FleetAlert(id: 2, type: 'low_fuel', severity: FipSeverity.medium),
              ),
            ],
          ),
        ),
      ),
    );

    expect(tester.takeException(), isNull);
    expect(find.byType(VehicleCard), findsNWidgets(2));
  });

  testWidgets('FipEmptyState sizes to its content inside a bounded Stack', (tester) async {
    // On the map the empty state sits in a Stack, where a MainAxisSize.max
    // Column grew to the full screen height and hid the summary card behind
    // it. In a scroll view the same widget looked fine, which is why it got
    // through.
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: Stack(
            children: [
              Align(
                child: Padding(
                  padding: EdgeInsets.all(16),
                  child: FipEmptyState(
                    icon: Icons.location_searching_rounded,
                    title: 'No vehicles reporting',
                    message: 'Positions appear here once a registered device reports.',
                    compact: true,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );

    expect(tester.takeException(), isNull);

    final size = tester.getSize(find.byType(FipEmptyState));
    final screen = tester.getSize(find.byType(Scaffold));

    expect(
      size.height,
      lessThan(screen.height / 2),
      reason: 'the empty state must hug its content, not fill the screen',
    );
  });
}

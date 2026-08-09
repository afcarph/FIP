import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/metric_card.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../../fleet/data/fleet_models.dart';
import '../../fleet/data/fleet_providers.dart';

/// Reports, for roles whose job is oversight rather than operations.
///
/// Everything here is computed from the fleet the caller is already allowed to
/// read. Nothing is fetched that a viewer would be refused, so the screen is
/// the same whether the reader is a viewer or a manager — the difference is
/// what the API returns, not what the screen asks for.
class ReportsScreen extends ConsumerWidget {
  const ReportsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicles = ref.watch(fleetVehiclesProvider);
    final alerts = ref.watch(fleetAlertsProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(fleetVehiclesProvider);
            ref.invalidate(fleetAlertsProvider);
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.only(bottom: FipSpace.xxl),
            children: [
              const FipHeader(title: 'Reports', subtitle: 'Fleet and fuel summaries'),

              vehicles.when(
                loading: () => const SizedBox(
                  height: 140,
                  child: Center(child: CircularProgressIndicator()),
                ),
                error: (error, _) => Padding(
                  padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                  child: FipEmptyState(
                    icon: Icons.cloud_off_rounded,
                    title: 'Could not load the fleet',
                    message: '$error',
                    action: 'Retry',
                    onAction: () => ref.invalidate(fleetVehiclesProvider),
                  ),
                ),
                data: (list) => _Summaries(
                  vehicles: list,
                  openAlerts: alerts.maybeWhen(
                    data: (a) => a.length,
                    orElse: () => 0,
                  ),
                ),
              ),

              const SectionHeader(
                title: 'Generated reports',
                subtitle: 'Scheduled exports',
                icon: Icons.summarize_rounded,
              ),
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: FipEmptyState(
                  icon: Icons.summarize_rounded,
                  title: 'No generated reports',
                  message:
                      'Reports produced by the reporting service appear here once any have been run.',
                  compact: true,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Summaries extends StatelessWidget {
  const _Summaries({required this.vehicles, required this.openAlerts});

  final List<FleetVehicle> vehicles;
  final int openAlerts;

  @override
  Widget build(BuildContext context) {
    final measured = vehicles.where((v) => v.fuelPercentage != null).toList();
    final efficiencies = vehicles.map((v) => v.efficiency).whereType<double>().toList();
    final litres = vehicles.map((v) => v.fuelLitres).whereType<double>();

    final averageTank = measured.isEmpty
        ? null
        : measured.map((v) => v.fuelPercentage!).reduce((a, b) => a + b) / measured.length;
    final averageEfficiency = efficiencies.isEmpty
        ? null
        : efficiencies.reduce((a, b) => a + b) / efficiencies.length;

    return Column(
      children: [
        const SectionHeader(title: 'Fleet', icon: Icons.local_shipping_rounded),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
          child: Row(
            children: [
              Expanded(
                child: MetricCard(
                  label: 'Vehicles',
                  value: '${vehicles.length}',
                  compact: true,
                ),
              ),
              const SizedBox(width: FipSpace.gap),
              Expanded(
                child: MetricCard(
                  label: 'Reporting',
                  value: '${vehicles.where((v) => v.isReporting).length}',
                  hint: 'of ${vehicles.length}',
                  compact: true,
                ),
              ),
              const SizedBox(width: FipSpace.gap),
              Expanded(
                child: MetricCard(
                  label: 'Open alerts',
                  value: '$openAlerts',
                  tone: openAlerts > 0 ? FleetStatus.attention.color(context) : null,
                  compact: true,
                ),
              ),
            ],
          ),
        ),

        const SectionHeader(title: 'Fuel', icon: Icons.local_gas_station_rounded),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
          child: Row(
            children: [
              Expanded(
                child: MetricCard(
                  label: 'Average tank',
                  value: averageTank?.toStringAsFixed(0) ?? '—',
                  unit: averageTank == null ? null : '%',
                  tone: fuelColor(context, averageTank),
                  hint: '${measured.length} measured',
                  compact: true,
                ),
              ),
              const SizedBox(width: FipSpace.gap),
              Expanded(
                child: MetricCard(
                  label: 'Fuel on hand',
                  value: litres.isEmpty
                      ? '—'
                      : litres.reduce((a, b) => a + b).toStringAsFixed(0),
                  unit: litres.isEmpty ? null : 'L',
                  compact: true,
                ),
              ),
              const SizedBox(width: FipSpace.gap),
              Expanded(
                child: MetricCard(
                  label: 'Efficiency',
                  value: averageEfficiency?.toStringAsFixed(1) ?? '—',
                  unit: averageEfficiency == null ? null : 'km/L',
                  hint: efficiencies.isEmpty ? 'No data' : 'Fleet avg',
                  compact: true,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

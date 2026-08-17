import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/metric_card.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../data/fleet_models.dart';
import '../data/fleet_providers.dart';

/// Fuel Intelligence — "understand your fuel".
///
/// Everything here is derived from readings that exist. Vehicles with no
/// reading are reported as unmeasured rather than folded into an average,
/// because a fleet average that silently treats missing tanks as empty is
/// worse than no average at all.
class FuelIntelligenceScreen extends ConsumerWidget {
  const FuelIntelligenceScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicles = ref.watch(fleetVehiclesProvider);
    final alerts = ref.watch(fleetAlertsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Fuel intelligence'),
        leading: const FipBackButton(fallback: '/home'),
      ),
      body: SafeArea(
        bottom: false,
        child: vehicles.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (error, _) => Padding(
            padding: const EdgeInsets.all(FipSpace.page),
            child: FipEmptyState(
              icon: Icons.cloud_off_rounded,
              title: 'Could not load fuel data',
              message: '$error',
              action: 'Retry',
              onAction: () => ref.invalidate(fleetVehiclesProvider),
            ),
          ),
          data: (list) {
            final measured = list.where((v) => v.fuelPercentage != null).toList();
            final efficiencies = list.map((v) => v.efficiency).whereType<double>().toList();
            final average = measured.isEmpty
                ? null
                : measured.map((v) => v.fuelPercentage!).reduce((a, b) => a + b) / measured.length;
            final efficiency = efficiencies.isEmpty
                ? null
                : efficiencies.reduce((a, b) => a + b) / efficiencies.length;
            final litres = list.map((v) => v.fuelLitres).whereType<double>();
            final lossAlerts = alerts.maybeWhen(
              data: (a) => a
                  .where((x) => x.type == 'fuel_loss' || x.type == 'unexplained_drop')
                  .toList(),
              orElse: () => const <FleetAlert>[],
            );

            return ListView(
              padding: const EdgeInsets.only(bottom: FipSpace.xxl),
              children: [
                const SectionHeader(
                  title: 'Fleet fuel',
                  subtitle: 'Current tank levels',
                  icon: Icons.local_gas_station_rounded,
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                  child: Row(
                    children: [
                      Expanded(
                        child: MetricCard(
                          label: 'Average tank',
                          value: average == null ? '—' : average.toStringAsFixed(0),
                          unit: average == null ? null : '%',
                          tone: fuelColor(context, average),
                          hint: '${measured.length} of ${list.length} measured',
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
                          hint: litres.isEmpty ? 'No readings' : 'Across measured tanks',
                          compact: true,
                        ),
                      ),
                    ],
                  ),
                ),

                const SectionHeader(
                  title: 'Efficiency',
                  subtitle: 'Consumption against baseline',
                  icon: Icons.trending_up_rounded,
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                  child: efficiencies.isEmpty
                      ? const FipEmptyState(
                          icon: Icons.trending_up_rounded,
                          title: 'Not enough data yet',
                          message:
                              'Efficiency needs at least two fill-ups with odometer readings for a vehicle.',
                          compact: true,
                        )
                      : Column(
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: MetricCard(
                                    label: 'Fleet average',
                                    value: efficiency!.toStringAsFixed(1),
                                    unit: 'km/L',
                                    compact: true,
                                  ),
                                ),
                                const SizedBox(width: FipSpace.gap),
                                Expanded(
                                  child: MetricCard(
                                    label: 'Vehicles rated',
                                    value: '${efficiencies.length}',
                                    hint: 'of ${list.length}',
                                    compact: true,
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: FipSpace.gap),
                            ...list
                                .where((v) => v.efficiency != null)
                                .map(
                                  (v) => Padding(
                                    padding: const EdgeInsets.only(bottom: FipSpace.gap),
                                    child: _EfficiencyRow(
                                      vehicle: v,
                                      onTap: () => context.push('/fleet/${v.id}'),
                                    ),
                                  ),
                                ),
                          ],
                        ),
                ),

                const SectionHeader(
                  title: 'Fuel-loss detection',
                  subtitle: 'Drops with no matching fill-up',
                  icon: Icons.error_outline_rounded,
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                  child: lossAlerts.isEmpty
                      ? const FipEmptyState(
                          icon: Icons.shield_rounded,
                          title: 'No fuel loss detected',
                          message:
                              'Every tank drop so far is explained by a recorded fill-up or normal consumption.',
                          compact: true,
                        )
                      : Column(
                          children: [
                            for (final alert in lossAlerts)
                              Padding(
                                padding: const EdgeInsets.only(bottom: FipSpace.gap),
                                child: FipCard(
                                  accent: alert.severity.color(context),
                                  padding: const EdgeInsets.all(FipSpace.md + 2),
                                  onTap: alert.vehicleId == null
                                      ? null
                                      : () => context.push('/fleet/${alert.vehicleId}'),
                                  child: Row(
                                    children: [
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              alert.title,
                                              style: Theme.of(context).textTheme.titleSmall
                                                  ?.copyWith(fontWeight: FontWeight.w700),
                                            ),
                                            Text(
                                              '${alert.vehiclePlate ?? 'Unknown vehicle'} · ${alert.detectedLabel}',
                                              style: FipType.caption(context),
                                            ),
                                          ],
                                        ),
                                      ),
                                      if (alert.score != null)
                                        Text(
                                          '${(alert.score! * 100).toStringAsFixed(0)}%',
                                          style: FipType.kpiSmall(context).copyWith(
                                            color: alert.severity.color(context),
                                          ),
                                        ),
                                    ],
                                  ),
                                ),
                              ),
                          ],
                        ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _EfficiencyRow extends StatelessWidget {
  const _EfficiencyRow({required this.vehicle, this.onTap});

  final FleetVehicle vehicle;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final deviation = vehicle.efficiencyDeviation;
    final worse = deviation != null && deviation < -5;

    return FipCard(
      onTap: onTap,
      padding: const EdgeInsets.all(FipSpace.md + 2),
      child: Row(
        children: [
          Expanded(
            child: Text(
              vehicle.plateNumber,
              style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
            ),
          ),
          if (deviation != null) ...[
            Icon(
              worse ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded,
              size: 14,
              color: worse ? FleetStatus.attention.color(context) : FipBrand.green,
            ),
            Text(
              '${deviation.abs().toStringAsFixed(0)}%',
              style: Theme.of(context).textTheme.labelMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: worse ? FleetStatus.attention.color(context) : FipBrand.green,
              ),
            ),
            const SizedBox(width: FipSpace.md),
          ],
          Text(
            '${vehicle.efficiency!.toStringAsFixed(1)} km/L',
            style: Theme.of(context).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
  }
}

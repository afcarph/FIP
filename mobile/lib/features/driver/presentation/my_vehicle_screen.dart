import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/fuel_card.dart';
import '../../../shared/widgets/fip/metric_card.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../../../shared/widgets/fip/status_badge.dart';
import '../data/driver_providers.dart';

/// The driver's vehicle, in detail.
///
/// Where Home answers "is everything fine", this answers "what exactly is the
/// state of my vehicle". It shows only what the API measures: tank, efficiency
/// where a baseline exists, odometer, and the last reported position. No trip
/// distance and no fuel cost — neither has a data source.
class MyVehicleScreen extends ConsumerWidget {
  const MyVehicleScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicle = ref.watch(assignedVehicleProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(assignedVehicleProvider),
          child: vehicle.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (error, _) => ListView(
              padding: const EdgeInsets.all(FipSpace.page),
              children: [
                FipEmptyState(
                  icon: Icons.cloud_off_rounded,
                  title: 'Could not load your vehicle',
                  message: '$error',
                  action: 'Retry',
                  onAction: () => ref.invalidate(assignedVehicleProvider),
                ),
              ],
            ),
            data: (v) => ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.only(bottom: FipSpace.xxl),
              children: [
                FipHeader(
                  title: v?.plateNumber ?? 'My vehicle',
                  subtitle: v?.displayName ?? 'Assigned vehicle',
                ),

                if (v == null)
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: FipSpace.page),
                    child: FipEmptyState(
                      icon: Icons.no_transfer_rounded,
                      title: 'No vehicle assigned',
                      message:
                          'A fleet administrator needs to assign a vehicle to you. Until then there is nothing to show here and nothing is being tracked.',
                    ),
                  )
                else ...[
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                    child: FipCard(
                      child: Row(
                        children: [
                          Icon(v.status.icon, color: v.status.color(context)),
                          const SizedBox(width: FipSpace.md),
                          Expanded(
                            child: Text(
                              v.locationRecordedAt == null
                                  ? 'No position reported yet'
                                  : 'Last position ${v.lastSeenLabel}',
                              style: Theme.of(context).textTheme.bodyMedium,
                            ),
                          ),
                          StatusBadge.fleet(v.status, context, dense: true),
                        ],
                      ),
                    ),
                  ),

                  const SectionHeader(title: 'Fuel', icon: Icons.local_gas_station_rounded),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                    child: FuelCard(
                      percentage: v.fuelPercentage,
                      litres: v.fuelLitres,
                      capacity: v.tankCapacity,
                      recordedLabel: v.fuelRecordedLabel,
                      isStale: v.fuelIsStale,
                    ),
                  ),

                  const SectionHeader(title: 'Measurements', icon: Icons.straighten_rounded),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                    child: Row(
                      children: [
                        Expanded(
                          child: MetricCard(
                            label: 'Efficiency',
                            value: v.efficiency?.toStringAsFixed(1) ?? '—',
                            unit: v.efficiency == null ? null : 'km/L',
                            hint: v.efficiency == null ? 'Needs fill-ups' : null,
                            compact: true,
                          ),
                        ),
                        const SizedBox(width: FipSpace.gap),
                        Expanded(
                          child: MetricCard(
                            label: 'Range',
                            value: v.estimatedRangeKm?.toStringAsFixed(0) ?? '—',
                            unit: v.estimatedRangeKm == null ? null : 'km',
                            compact: true,
                          ),
                        ),
                        const SizedBox(width: FipSpace.gap),
                        Expanded(
                          child: MetricCard(
                            label: 'Odometer',
                            value: v.odometer?.toStringAsFixed(0) ?? '—',
                            unit: v.odometer == null ? null : 'km',
                            compact: true,
                          ),
                        ),
                      ],
                    ),
                  ),

                  const SectionHeader(title: 'Actions', icon: Icons.bolt_rounded),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                    child: Row(
                      children: [
                        Expanded(
                          child: _Action(
                            icon: Icons.document_scanner_rounded,
                            label: 'Scan receipt',
                            onTap: () => context.push('/scan'),
                          ),
                        ),
                        const SizedBox(width: FipSpace.gap),
                        Expanded(
                          child: _Action(
                            icon: Icons.local_gas_station_rounded,
                            label: 'Log refuel',
                            onTap: () => context.push('/expenses'),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Action extends StatelessWidget {
  const _Action({required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return FipCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(vertical: FipSpace.lg),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 22, color: Theme.of(context).colorScheme.primary),
          const SizedBox(height: FipSpace.sm),
          Text(
            label,
            style: Theme.of(context).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}

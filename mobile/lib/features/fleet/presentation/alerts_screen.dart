import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/widgets/fip/alert_card.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../data/fleet_models.dart';
import '../data/fleet_providers.dart';

/// Alerts — the queue of things asking for a decision.
///
/// Grouped by severity rather than time. A critical fuel-loss alert from
/// yesterday outranks a low-severity one from an hour ago, and a strict
/// reverse-chronological list buries exactly the wrong thing.
class AlertsScreen extends ConsumerWidget {
  const AlertsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final alerts = ref.watch(fleetAlertsProvider);
    final vehicles = ref.watch(fleetVehiclesProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(fleetAlertsProvider);
            ref.invalidate(fleetVehiclesProvider);
          },
          child: CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              SliverToBoxAdapter(
                child: FipHeader(
                  title: 'Alerts',
                  subtitle: alerts.maybeWhen(
                    data: (list) => list.isEmpty
                        ? 'Nothing open'
                        : '${list.length} open ${list.length == 1 ? 'alert' : 'alerts'}',
                    orElse: () => 'Loading',
                  ),
                ),
              ),

              // Low fuel is derived from tank readings rather than from the
              // alerts table, so it is shown as its own band. It is a
              // condition, not an incident somebody has to resolve.
              ...vehicles.maybeWhen(
                data: (list) {
                  final low = list
                      .where((v) => v.fuelPercentage != null && v.fuelPercentage! <= 25)
                      .toList();
                  if (low.isEmpty) return const <Widget>[];

                  return [
                    const SliverToBoxAdapter(
                      child: SectionHeader(
                        title: 'Low fuel',
                        icon: Icons.local_gas_station_rounded,
                      ),
                    ),
                    SliverPadding(
                      padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                      sliver: SliverList.separated(
                        itemCount: low.length,
                        separatorBuilder: (_, _) => const SizedBox(height: FipSpace.gap),
                        itemBuilder: (context, i) => _LowFuelRow(
                          vehicle: low[i],
                          onTap: () => context.push('/fleet/${low[i].id}'),
                        ),
                      ),
                    ),
                  ];
                },
                orElse: () => const <Widget>[],
              ),

              const SliverToBoxAdapter(
                child: SectionHeader(
                  title: 'Fuel & tracking alerts',
                  icon: Icons.notifications_active_rounded,
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(
                  FipSpace.page,
                  0,
                  FipSpace.page,
                  FipSpace.xxl,
                ),
                sliver: alerts.when(
                  loading: () => const SliverToBoxAdapter(
                    child: Center(
                      child: Padding(padding: EdgeInsets.all(32), child: CircularProgressIndicator()),
                    ),
                  ),
                  error: (error, _) => SliverToBoxAdapter(
                    child: FipEmptyState(
                      icon: Icons.cloud_off_rounded,
                      title: 'Could not load alerts',
                      message: '$error',
                      action: 'Retry',
                      onAction: () => ref.invalidate(fleetAlertsProvider),
                    ),
                  ),
                  data: (list) {
                    if (list.isEmpty) {
                      return const SliverToBoxAdapter(
                        child: FipEmptyState(
                          icon: Icons.verified_rounded,
                          title: 'No open alerts',
                          message:
                              'Fuel loss, unexplained drops and tracking gaps appear here the moment detection flags them.',
                        ),
                      );
                    }

                    final sorted = [...list]
                      ..sort((a, b) {
                        final bySeverity = a.severity.index.compareTo(b.severity.index);
                        if (bySeverity != 0) return bySeverity;

                        return (b.detectedAt ?? DateTime(0)).compareTo(a.detectedAt ?? DateTime(0));
                      });

                    return SliverList.separated(
                      itemCount: sorted.length,
                      separatorBuilder: (_, _) => const SizedBox(height: FipSpace.gap),
                      itemBuilder: (context, i) => AlertCard(
                        alert: sorted[i],
                        onTap: sorted[i].vehicleId == null
                            ? null
                            : () => context.push('/fleet/${sorted[i].vehicleId}'),
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LowFuelRow extends StatelessWidget {
  const _LowFuelRow({required this.vehicle, this.onTap});

  final FleetVehicle vehicle;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final color = fuelColor(context, vehicle.fuelPercentage);

    return FipCard(
      onTap: onTap,
      accent: color,
      padding: const EdgeInsets.all(FipSpace.md + 2),
      child: Row(
        children: [
          Icon(Icons.local_gas_station_rounded, size: 18, color: color),
          const SizedBox(width: FipSpace.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  vehicle.plateNumber,
                  style: Theme.of(
                    context,
                  ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                ),
                Text(
                  'Measured ${vehicle.fuelRecordedLabel}',
                  style: FipType.caption(context),
                ),
              ],
            ),
          ),
          Text(
            '${vehicle.fuelPercentage!.toStringAsFixed(0)}%',
            style: FipType.kpiSmall(context).copyWith(color: color),
          ),
        ],
      ),
    );
  }
}

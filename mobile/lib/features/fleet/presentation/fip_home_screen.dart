import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/fip/alert_card.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/fleet_pulse.dart';
import '../../../shared/widgets/fip/metric_card.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../../../shared/widgets/fip/vehicle_card.dart';
import '../../tracking/presentation/tracking_indicator.dart';
import '../data/fleet_models.dart';
import '../data/fleet_providers.dart';

/// Home — "see your fleet".
///
/// Ordered by urgency rather than by data source: the pulse, anything on
/// fire, then the fuel picture, then the vehicles themselves. A fleet
/// operator opening this at 7am wants to know whether today is normal before
/// they want any individual number.
class FipHomeScreen extends ConsumerWidget {
  const FipHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicles = ref.watch(fleetVehiclesProvider);
    final alerts = ref.watch(fleetAlertsProvider);
    final auth = ref.watch(authProvider);
    final firstName = auth.user?['first_name'] as String?;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(fleetVehiclesProvider),
          child: CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              SliverToBoxAdapter(
                child: FipHeader(
                  title: _greeting(firstName),
                  subtitle: 'See your fleet. Understand your fuel.',
                ),
              ),

              // Tracking state stays directly under the header. Phase 3 put it
              // where a driver cannot miss it, and that placement is a privacy
              // commitment, not a layout preference.
              const SliverPadding(
                padding: EdgeInsets.fromLTRB(FipSpace.page, FipSpace.sm, FipSpace.page, 0),
                sliver: SliverToBoxAdapter(child: TrackingIndicator()),
              ),

              SliverPadding(
                padding: const EdgeInsets.fromLTRB(
                  FipSpace.page,
                  FipSpace.md,
                  FipSpace.page,
                  0,
                ),
                sliver: SliverToBoxAdapter(
                  child: vehicles.when(
                    loading: () => const _PulseSkeleton(),
                    error: (_, _) => const _PulseSkeleton(),
                    data: (list) {
                      final pulse = FleetPulseData.from(list);

                      return FleetPulse(
                        active: pulse.active,
                        moving: pulse.moving,
                        stopped: pulse.stopped,
                        attention: pulse.attention,
                        total: pulse.total,
                        onAttentionTap: () => context.go('/alerts'),
                      );
                    },
                  ),
                ),
              ),

              // ------------------------------------------- fuel intelligence ---
              SliverToBoxAdapter(
                child: SectionHeader(
                  title: 'Fuel intelligence',
                  subtitle: 'Across the fleet',
                  icon: Icons.insights_rounded,
                  action: 'Details',
                  onAction: () => context.push('/fuel'),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                sliver: SliverToBoxAdapter(
                  child: vehicles.when(
                    loading: () => const SizedBox(height: 96),
                    error: (_, _) => const SizedBox(height: 0),
                    data: (list) => _FuelSummary(vehicles: list),
                  ),
                ),
              ),

              // -------------------------------------------------- recent alerts ---
              SliverToBoxAdapter(
                child: SectionHeader(
                  title: 'Recent alerts',
                  icon: Icons.notifications_active_rounded,
                  action: 'All',
                  onAction: () => context.go('/alerts'),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                sliver: alerts.maybeWhen(
                  data: (list) => list.isEmpty
                      ? const SliverToBoxAdapter(
                          child: FipEmptyState(
                            icon: Icons.verified_rounded,
                            title: 'No open alerts',
                            message:
                                'Fuel-loss and low-fuel alerts appear here as soon as they are detected.',
                            compact: true,
                          ),
                        )
                      : SliverList.separated(
                          itemCount: list.length > 3 ? 3 : list.length,
                          separatorBuilder: (_, _) => const SizedBox(height: FipSpace.gap),
                          itemBuilder: (context, i) => AlertCard(
                            alert: list[i],
                            onTap: () => context.go('/alerts'),
                          ),
                        ),
                  orElse: () => const SliverToBoxAdapter(child: SizedBox(height: 0)),
                ),
              ),

              // -------------------------------------------------- vehicle status ---
              SliverToBoxAdapter(
                child: SectionHeader(
                  title: 'Vehicle status',
                  icon: Icons.local_shipping_rounded,
                  action: 'Fleet',
                  onAction: () => context.go('/fleet'),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(
                  FipSpace.page,
                  0,
                  FipSpace.page,
                  FipSpace.xxl,
                ),
                sliver: vehicles.when(
                  loading: () => const SliverToBoxAdapter(
                    child: Center(child: Padding(padding: EdgeInsets.all(32), child: CircularProgressIndicator())),
                  ),
                  error: (error, _) => SliverToBoxAdapter(
                    child: FipEmptyState(
                      icon: Icons.cloud_off_rounded,
                      title: 'Could not load the fleet',
                      message: '$error',
                      action: 'Retry',
                      onAction: () => ref.invalidate(fleetVehiclesProvider),
                    ),
                  ),
                  data: (list) => list.isEmpty
                      ? const SliverToBoxAdapter(
                          child: FipEmptyState(
                            icon: Icons.local_shipping_rounded,
                            title: 'No vehicles yet',
                            message:
                                'Vehicles added to your fleet appear here with their fuel level and last known position.',
                          ),
                        )
                      : SliverList.separated(
                          itemCount: list.length > 4 ? 4 : list.length,
                          separatorBuilder: (_, _) => const SizedBox(height: FipSpace.gap),
                          itemBuilder: (context, i) => VehicleCard(
                            vehicle: list[i],
                            onTap: () => context.push('/fleet/${list[i].id}'),
                          ),
                        ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  static String _greeting(String? name) {
    final hour = DateTime.now().hour;
    final part = hour < 12
        ? 'Good morning'
        : hour < 18
        ? 'Good afternoon'
        : 'Good evening';

    return name == null ? part : '$part, $name';
  }
}

/// Fleet-wide fuel, from readings that exist. Vehicles without a reading are
/// counted separately rather than averaged in as zero.
class _FuelSummary extends StatelessWidget {
  const _FuelSummary({required this.vehicles});

  final List<FleetVehicle> vehicles;

  @override
  Widget build(BuildContext context) {
    final measured = vehicles.where((v) => v.fuelPercentage != null).toList();
    final low = measured.where((v) => v.fuelPercentage! <= 25).length;
    final efficiencies = vehicles.map((v) => v.efficiency).whereType<double>().toList();

    final average = measured.isEmpty
        ? null
        : measured.map((v) => v.fuelPercentage!).reduce((a, b) => a + b) / measured.length;
    final efficiency = efficiencies.isEmpty
        ? null
        : efficiencies.reduce((a, b) => a + b) / efficiencies.length;

    return Row(
      children: [
        Expanded(
          child: MetricCard(
            label: 'Avg tank',
            value: average == null ? '—' : average.toStringAsFixed(0),
            unit: average == null ? null : '%',
            icon: Icons.local_gas_station_rounded,
            tone: fuelColor(context, average),
            hint: measured.isEmpty ? 'No readings' : '${measured.length} measured',
            compact: true,
          ),
        ),
        const SizedBox(width: FipSpace.gap),
        Expanded(
          child: MetricCard(
            label: 'Efficiency',
            value: efficiency == null ? '—' : efficiency.toStringAsFixed(1),
            unit: efficiency == null ? null : 'km/L',
            icon: Icons.trending_up_rounded,
            hint: efficiencies.isEmpty ? 'No data' : 'Fleet avg',
            compact: true,
          ),
        ),
        const SizedBox(width: FipSpace.gap),
        Expanded(
          child: MetricCard(
            label: 'Low fuel',
            value: '$low',
            icon: Icons.warning_amber_rounded,
            tone: low > 0 ? FleetStatus.attention.color(context) : null,
            hint: low == 0 ? 'None ≤25%' : 'At or below 25%',
            compact: true,
          ),
        ),
      ],
    );
  }
}

class _PulseSkeleton extends StatelessWidget {
  const _PulseSkeleton();

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 168,
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(FipRadius.card),
        border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
      ),
    );
  }
}

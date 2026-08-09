import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/vehicle_card.dart';
import '../data/fleet_providers.dart';

/// Fleet — every vehicle, filterable by what it is doing.
///
/// The filter row is counts, not just labels: an operator deciding whether to
/// look at "offline" wants to know how many there are before tapping.
class FleetScreen extends ConsumerStatefulWidget {
  const FleetScreen({super.key});

  @override
  ConsumerState<FleetScreen> createState() => _FleetScreenState();
}

class _FleetScreenState extends ConsumerState<FleetScreen> {
  FleetStatus? _filter;

  @override
  Widget build(BuildContext context) {
    final vehicles = ref.watch(fleetVehiclesProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(fleetVehiclesProvider),
          child: vehicles.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (error, _) => ListView(
              padding: const EdgeInsets.all(FipSpace.page),
              children: [
                FipEmptyState(
                  icon: Icons.cloud_off_rounded,
                  title: 'Could not load the fleet',
                  message: '$error',
                  action: 'Retry',
                  onAction: () => ref.invalidate(fleetVehiclesProvider),
                ),
              ],
            ),
            data: (list) {
              final filtered = _filter == null
                  ? list
                  : list.where((v) => v.status == _filter).toList();

              return CustomScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                slivers: [
                  SliverToBoxAdapter(
                    child: FipHeader(
                      title: 'Fleet',
                      subtitle: list.isEmpty
                          ? 'No vehicles'
                          : '${list.length} ${list.length == 1 ? 'vehicle' : 'vehicles'}',
                    ),
                  ),
                  SliverToBoxAdapter(
                    child: SizedBox(
                      height: 44,
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                        children: [
                          _Chip(
                            label: 'All',
                            count: list.length,
                            selected: _filter == null,
                            onTap: () => setState(() => _filter = null),
                          ),
                          for (final status in FleetStatus.values)
                            _Chip(
                              label: status.label,
                              count: list.where((v) => v.status == status).length,
                              color: status.color(context),
                              selected: _filter == status,
                              onTap: () => setState(
                                () => _filter = _filter == status ? null : status,
                              ),
                            ),
                        ],
                      ),
                    ),
                  ),
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(
                      FipSpace.page,
                      FipSpace.md,
                      FipSpace.page,
                      FipSpace.xxl,
                    ),
                    sliver: filtered.isEmpty
                        ? SliverToBoxAdapter(
                            child: FipEmptyState(
                              icon: Icons.local_shipping_rounded,
                              title: list.isEmpty ? 'No vehicles yet' : 'Nothing in this state',
                              message: list.isEmpty
                                  ? 'Vehicles added to your fleet appear here with fuel level, efficiency and last known position.'
                                  : 'No vehicle is currently ${_filter!.label.toLowerCase()}.',
                            ),
                          )
                        : SliverList.separated(
                            itemCount: filtered.length,
                            separatorBuilder: (_, _) => const SizedBox(height: FipSpace.gap),
                            itemBuilder: (context, i) => VehicleCard(
                              vehicle: filtered[i],
                              onTap: () => context.push('/fleet/${filtered[i].id}'),
                            ),
                          ),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({
    required this.label,
    required this.count,
    required this.selected,
    required this.onTap,
    this.color,
  });

  final String label;
  final int count;
  final bool selected;
  final VoidCallback onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final tint = color ?? scheme.primary;

    return Padding(
      padding: const EdgeInsets.only(right: FipSpace.sm),
      child: Material(
        color: selected ? tint.withValues(alpha: 0.14) : scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(FipRadius.pill),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(FipRadius.pill),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: FipSpace.md, vertical: FipSpace.sm),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(FipRadius.pill),
              border: Border.all(color: selected ? tint : scheme.outlineVariant),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  label,
                  style: Theme.of(context).textTheme.labelMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                    color: selected ? tint : scheme.onSurface,
                  ),
                ),
                const SizedBox(width: 5),
                Text(
                  '$count',
                  style: Theme.of(context).textTheme.labelMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: selected ? tint : scheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

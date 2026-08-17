import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/widgets/fip/alert_card.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/fuel_card.dart';
import '../../../shared/widgets/fip/metric_card.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../../../shared/widgets/fip/status_badge.dart';
import '../data/fleet_models.dart';
import '../data/fleet_providers.dart';

/// One vehicle, in full.
///
/// Location history and fuel history are both permission-gated server-side,
/// so each renders its own empty state rather than the screen failing as a
/// whole when a role cannot see one of them.
class VehicleDetailScreen extends ConsumerWidget {
  const VehicleDetailScreen({super.key, required this.vehicleId});

  final int vehicleId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicle = ref.watch(fleetVehicleProvider(vehicleId));
    final alerts = ref.watch(fleetAlertsProvider);
    final locations = ref.watch(vehicleLocationHistoryProvider(vehicleId));
    final fuelHistory = ref.watch(vehicleFuelHistoryProvider(vehicleId));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehicle'),
        leading: const FipBackButton(fallback: '/fleet'),
      ),
      body: vehicle.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => Padding(
          padding: const EdgeInsets.all(FipSpace.page),
          child: FipEmptyState(
            icon: Icons.cloud_off_rounded,
            title: 'Could not load this vehicle',
            message: '$error',
          ),
        ),
        data: (v) {
          if (v == null) {
            return const Padding(
              padding: EdgeInsets.all(FipSpace.page),
              child: FipEmptyState(
                icon: Icons.help_outline_rounded,
                title: 'Vehicle not found',
                message: 'It may have been removed from your fleet.',
              ),
            );
          }

          final mine = alerts.maybeWhen(
            data: (list) => list.where((a) => a.vehicleId == v.id).toList(),
            orElse: () => const <FleetAlert>[],
          );

          return ListView(
            padding: const EdgeInsets.only(bottom: FipSpace.xxl),
            children: [
              // ------------------------------------------------- identity ---
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  FipSpace.page,
                  FipSpace.sm,
                  FipSpace.page,
                  0,
                ),
                child: FipCard(
                  child: Row(
                    children: [
                      Container(
                        width: 46,
                        height: 46,
                        decoration: BoxDecoration(
                          color: v.status.color(context).withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(FipRadius.control),
                        ),
                        child: Icon(
                          Icons.local_shipping_rounded,
                          color: v.status.color(context),
                        ),
                      ),
                      const SizedBox(width: FipSpace.md),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              v.plateNumber,
                              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            if (v.displayName != null)
                              Text(v.displayName!, style: FipType.caption(context)),
                          ],
                        ),
                      ),
                      StatusBadge.fleet(v.status, context),
                    ],
                  ),
                ),
              ),

              // ----------------------------------------------------- fuel ---
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

              // ----------------------------------------------- efficiency ---
              const SectionHeader(title: 'Efficiency', icon: Icons.trending_up_rounded),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: Row(
                  children: [
                    Expanded(
                      child: MetricCard(
                        label: 'Average',
                        value: v.efficiency?.toStringAsFixed(1) ?? '—',
                        unit: v.efficiency == null ? null : 'km/L',
                        compact: true,
                      ),
                    ),
                    const SizedBox(width: FipSpace.gap),
                    Expanded(
                      child: MetricCard(
                        label: 'Range left',
                        value: v.estimatedRangeKm?.toStringAsFixed(0) ?? '—',
                        unit: v.estimatedRangeKm == null ? null : 'km',
                        compact: true,
                      ),
                    ),
                    const SizedBox(width: FipSpace.gap),
                    Expanded(
                      child: MetricCard(
                        label: 'Odometer',
                        value: v.odometer == null
                            ? '—'
                            : Formatters.number(v.odometer, decimals: 0),
                        unit: v.odometer == null ? null : 'km',
                        compact: true,
                      ),
                    ),
                  ],
                ),
              ),

              // ------------------------------------------------- location ---
              const SectionHeader(title: 'Current location', icon: Icons.place_rounded),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: v.hasLocation
                    ? FipCard(
                        child: Row(
                          children: [
                            Icon(v.status.icon, size: 18, color: v.status.color(context)),
                            const SizedBox(width: FipSpace.md),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    '${v.latitude!.toStringAsFixed(5)}, ${v.longitude!.toStringAsFixed(5)}',
                                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                  Text(
                                    'Updated ${v.lastSeenLabel}',
                                    style: FipType.caption(context),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      )
                    : const FipEmptyState(
                        icon: Icons.location_off_rounded,
                        title: 'No position reported',
                        message:
                            'This vehicle has no registered device reporting location, or has not reported yet.',
                        compact: true,
                      ),
              ),

              // ------------------------------------------ location history ---
              const SectionHeader(title: 'Location history', icon: Icons.timeline_rounded),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: locations.maybeWhen(
                  data: (list) => list.isEmpty
                      ? const FipEmptyState(
                          icon: Icons.timeline_rounded,
                          title: 'No location history',
                          message:
                              'History appears once a registered device reports, and requires the location-history permission.',
                          compact: true,
                        )
                      : FipCard(
                          child: Column(
                            children: [
                              for (final point in list.take(6))
                                Padding(
                                  padding: const EdgeInsets.symmetric(vertical: 5),
                                  child: Row(
                                    children: [
                                      const Icon(Icons.circle, size: 6),
                                      const SizedBox(width: FipSpace.sm),
                                      Expanded(
                                        child: Text(
                                          '${(point['latitude'] as num?)?.toStringAsFixed(4)}, ${(point['longitude'] as num?)?.toStringAsFixed(4)}',
                                          style: Theme.of(context).textTheme.bodySmall,
                                        ),
                                      ),
                                      Text(
                                        Formatters.relative(
                                          DateTime.tryParse(
                                            (point['recorded_at'] ?? '') as String,
                                          )?.toLocal(),
                                        ),
                                        style: FipType.caption(context),
                                      ),
                                    ],
                                  ),
                                ),
                            ],
                          ),
                        ),
                  orElse: () => const SizedBox(height: 0),
                ),
              ),

              // ---------------------------------------------- fuel history ---
              const SectionHeader(title: 'Fuel history', icon: Icons.history_rounded),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: fuelHistory.maybeWhen(
                  data: (list) => list.isEmpty
                      ? const FipEmptyState(
                          icon: Icons.history_rounded,
                          title: 'No fuel readings',
                          message:
                              'Readings appear here as fill-ups are recorded or the tank is measured.',
                          compact: true,
                        )
                      : FipCard(
                          child: Column(
                            children: [
                              for (final reading in list.take(6))
                                Padding(
                                  padding: const EdgeInsets.symmetric(vertical: 5),
                                  child: Row(
                                    children: [
                                      Icon(
                                        Icons.local_gas_station_rounded,
                                        size: 13,
                                        color: fuelColor(
                                          context,
                                          (reading['percentage'] as num?)?.toDouble(),
                                        ),
                                      ),
                                      const SizedBox(width: FipSpace.sm),
                                      Expanded(
                                        child: Text(
                                          '${(reading['percentage'] as num?)?.toStringAsFixed(0) ?? '—'}%',
                                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                            fontWeight: FontWeight.w600,
                                          ),
                                        ),
                                      ),
                                      Text(
                                        (reading['source'] as String?) ?? '',
                                        style: FipType.caption(context),
                                      ),
                                    ],
                                  ),
                                ),
                            ],
                          ),
                        ),
                  orElse: () => const SizedBox(height: 0),
                ),
              ),

              // --------------------------------------------------- alerts ---
              const SectionHeader(title: 'Alerts', icon: Icons.notifications_rounded),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: mine.isEmpty
                    ? const FipEmptyState(
                        icon: Icons.verified_rounded,
                        title: 'No open alerts',
                        message: 'Nothing has been flagged for this vehicle.',
                        compact: true,
                      )
                    : Column(
                        children: [
                          for (final alert in mine)
                            Padding(
                              padding: const EdgeInsets.only(bottom: FipSpace.gap),
                              child: AlertCard(alert: alert),
                            ),
                        ],
                      ),
              ),
            ],
          );
        },
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/fip/alert_card.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/fuel_card.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../../../shared/widgets/fip/status_badge.dart';
import '../../fleet/data/fleet_models.dart';
import '../../tracking/presentation/tracking_indicator.dart';
import '../data/device_setup.dart';
import '../data/driver_providers.dart';

/// Home, for a driver.
///
/// A driver has one vehicle and a small number of jobs, so this screen is not
/// the fleet dashboard with rows hidden. It shows their vehicle, whether the
/// phone is reporting, the tank, and anything flagged against that vehicle —
/// and nothing about the rest of the fleet, other drivers, or administration.
///
/// Deliberately absent: distance today, trip counts, litres per 100 km. The
/// API measures none of them, and inventing them on a screen a driver is
/// judged by would be worse than leaving the space empty.
class DriverHomeScreen extends ConsumerWidget {
  const DriverHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicle = ref.watch(assignedVehicleProvider);
    final alerts = ref.watch(driverAlertsProvider);
    final setup = ref.watch(deviceSetupProvider);
    final firstName = ref.watch(authProvider).user?['first_name'] as String?;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(assignedVehicleProvider);
            ref.invalidate(deviceRegistrationProvider);
            await ref.read(deviceSetupProvider.notifier).refresh();
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.only(bottom: FipSpace.xxl),
            children: [
              FipHeader(
                title: _greeting(firstName),
                subtitle: 'Your vehicle and today’s tracking',
              ),

              // Setup comes first when it is outstanding: nothing else on this
              // screen works until the phone is registered and attached.
              if (setup.step != DeviceSetupStep.ready)
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    FipSpace.page,
                    FipSpace.sm,
                    FipSpace.page,
                    0,
                  ),
                  child: _SetupCard(state: setup),
                ),

              const Padding(
                padding: EdgeInsets.fromLTRB(FipSpace.page, FipSpace.md, FipSpace.page, 0),
                child: TrackingIndicator(),
              ),

              const SectionHeader(title: 'My vehicle', icon: Icons.local_shipping_rounded),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: vehicle.when(
                  loading: () => const SizedBox(
                    height: 120,
                    child: Center(child: CircularProgressIndicator()),
                  ),
                  error: (error, _) => FipEmptyState(
                    icon: Icons.cloud_off_rounded,
                    title: 'Could not load your vehicle',
                    message: '$error',
                    action: 'Retry',
                    onAction: () => ref.invalidate(assignedVehicleProvider),
                  ),
                  data: (v) => v == null
                      ? const FipEmptyState(
                          icon: Icons.no_transfer_rounded,
                          title: 'No vehicle assigned',
                          message:
                              'A fleet administrator needs to assign a vehicle to you before tracking can start. '
                              'Nothing is reported until then.',
                        )
                      : _VehicleCard(vehicle: v),
                ),
              ),

              // ------------------------------------------------------- fuel ---
              ...vehicle.maybeWhen(
                data: (v) => v == null
                    ? const <Widget>[]
                    : [
                        const SectionHeader(
                          title: 'Fuel',
                          icon: Icons.local_gas_station_rounded,
                        ),
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
                        if (v.efficiency != null) ...[
                          const SizedBox(height: FipSpace.gap),
                          Padding(
                            padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                            child: _EfficiencyRow(vehicle: v),
                          ),
                        ],
                      ],
                orElse: () => const <Widget>[],
              ),

              // --------------------------------------------- quick actions ---
              const SectionHeader(title: 'Quick actions', icon: Icons.bolt_rounded),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: Row(
                  children: [
                    Expanded(
                      child: _Action(
                        icon: Icons.document_scanner_rounded,
                        label: 'Scan receipt',
                        onTap: () => context.push('/scan-receipt'),
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

              // --------------------------------------------------- alerts ---
              const SectionHeader(
                title: 'Alerts for my vehicle',
                icon: Icons.notifications_rounded,
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: alerts.maybeWhen(
                  data: (list) => list.isEmpty
                      ? const FipEmptyState(
                          icon: Icons.verified_rounded,
                          title: 'Nothing flagged',
                          message: 'Alerts raised against your vehicle appear here.',
                          compact: true,
                        )
                      : Column(
                          children: [
                            for (final alert in list)
                              Padding(
                                padding: const EdgeInsets.only(bottom: FipSpace.gap),
                                child: AlertCard(alert: alert),
                              ),
                          ],
                        ),
                  orElse: () => const SizedBox(height: 0),
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

/// Registration state, and the one button that resolves it.
class _SetupCard extends ConsumerWidget {
  const _SetupCard({required this.state});

  final DeviceSetupState state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);

    final (title, message, action, tone) = switch (state.step) {
      DeviceSetupStep.checking => ('Checking this device', 'One moment.', null, null),
      DeviceSetupStep.noAssignment => (
        'No vehicle assigned',
        'A fleet administrator needs to assign a vehicle to you before this phone can report.',
        null,
        FleetStatus.attention.color(context),
      ),
      DeviceSetupStep.needsSetup => (
        'Set up this device',
        'Link this phone to your vehicle so your position is shared while you drive. '
            'You will be asked for location permission after this.',
        'Set up',
        theme.colorScheme.primary,
      ),
      DeviceSetupStep.revoked => (
        'This device was removed',
        'A fleet administrator removed this phone. Contact them to restore it.',
        null,
        FleetStatus.attention.color(context),
      ),
      DeviceSetupStep.failed => (
        'Setup did not complete',
        state.message ?? 'Something went wrong.',
        'Try again',
        FleetStatus.attention.color(context),
      ),
      DeviceSetupStep.ready => ('Ready', '', null, null),
    };

    return FipCard(
      accent: tone,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: FipSpace.xs),
          Text(message, style: FipType.caption(context)),
          if (action != null) ...[
            const SizedBox(height: FipSpace.md),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: state.busy
                    ? null
                    : () => ref.read(deviceSetupProvider.notifier).completeSetup(),
                child: Text(state.busy ? 'Setting up…' : action),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _VehicleCard extends StatelessWidget {
  const _VehicleCard({required this.vehicle});

  final FleetVehicle vehicle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return FipCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: vehicle.status.color(context).withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(FipRadius.control),
                ),
                child: Icon(
                  Icons.local_shipping_rounded,
                  color: vehicle.status.color(context),
                ),
              ),
              const SizedBox(width: FipSpace.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      vehicle.plateNumber,
                      style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                    ),
                    if (vehicle.displayName != null)
                      Text(vehicle.displayName!, style: FipType.caption(context)),
                  ],
                ),
              ),
              StatusBadge.fleet(vehicle.status, context, dense: true),
            ],
          ),
          const SizedBox(height: FipSpace.md),
          Row(
            children: [
              Icon(Icons.schedule_rounded, size: 14, color: theme.colorScheme.onSurfaceVariant),
              const SizedBox(width: 5),
              Text(
                vehicle.locationRecordedAt == null
                    ? 'No position reported yet'
                    : 'Position updated ${vehicle.lastSeenLabel}',
                style: FipType.caption(context),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Efficiency, in the unit the API reports it in.
class _EfficiencyRow extends StatelessWidget {
  const _EfficiencyRow({required this.vehicle});

  final FleetVehicle vehicle;

  @override
  Widget build(BuildContext context) {
    return FipCard(
      padding: const EdgeInsets.all(FipSpace.md + 2),
      child: Row(
        children: [
          Icon(Icons.trending_up_rounded, size: 16, color: Theme.of(context).colorScheme.primary),
          const SizedBox(width: FipSpace.sm),
          Expanded(child: Text('Efficiency', style: FipType.caption(context))),
          Text(
            '${vehicle.efficiency!.toStringAsFixed(1)} km/L',
            style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
          ),
        ],
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
            style: Theme.of(
              context,
            ).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}

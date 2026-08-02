import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/error_view.dart';

class VehiclesScreen extends ConsumerWidget {
  const VehiclesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicles = ref.watch(vehiclesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Vehicles')),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(vehiclesProvider),
          child: vehicles.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (error, _) => ErrorView(error: error, onRetry: () => ref.invalidate(vehiclesProvider)),
            data: (data) => data.isEmpty
                ? const EmptyView(
                    icon: LucideIcons.car,
                    title: 'No vehicles yet',
                    description: 'Add a vehicle to track its efficiency and running costs.',
                  )
                : ListView.separated(
                    padding: const EdgeInsets.all(16),
                    physics: const AlwaysScrollableScrollPhysics(),
                    itemCount: data.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 12),
                    itemBuilder: (context, index) => _VehicleCard(vehicle: data[index]),
                  ),
          ),
        ),
      ),
    );
  }
}

class _VehicleCard extends StatelessWidget {
  const _VehicleCard({required this.vehicle});

  final Map<String, dynamic> vehicle;

  @override
  Widget build(BuildContext context) {
    final efficiency = vehicle['efficiency'] as Map<String, dynamic>? ?? {};
    final documents = vehicle['documents'] as Map<String, dynamic>? ?? {};
    final deviation = efficiency['deviation_pct'] as num?;

    final registrationDays = documents['registration_expires_in_days'] as num?;
    final insuranceDays = documents['insurance_expires_in_days'] as num?;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 22,
                  backgroundColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.12),
                  child: Icon(
                    _iconFor(vehicle['vehicle_type'] as String?),
                    color: Theme.of(context).colorScheme.primary,
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        vehicle['display_name'] as String? ?? 'Vehicle',
                        style: Theme.of(context).textTheme.titleSmall?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                      ),
                      Text(
                        [
                          vehicle['plate_number'],
                          (vehicle['fuel_type'] as Map<String, dynamic>?)?['name'],
                        ].where((part) => part != null).join(' · '),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: Theme.of(context).colorScheme.onSurfaceVariant,
                            ),
                      ),
                    ],
                  ),
                ),
              ],
            ),

            const SizedBox(height: 16),

            Row(
              children: [
                Expanded(
                  child: _Metric(
                    label: 'Odometer',
                    value: Formatters.distance(vehicle['current_odometer'] as num?),
                  ),
                ),
                Expanded(
                  child: _Metric(
                    label: 'Efficiency',
                    value: Formatters.efficiency(efficiency['avg_km_per_litre'] as num?),
                    // Only a meaningful drop is worth colouring; small
                    // variation between tanks is normal.
                    valueColor: deviation != null && deviation < -5 ? context.fipColors.priceUp : null,
                  ),
                ),
                Expanded(
                  child: _Metric(
                    label: 'Range',
                    value: Formatters.distance(efficiency['estimated_range_km'] as num?),
                  ),
                ),
              ],
            ),

            if (_isExpiringSoon(registrationDays) || _isExpiringSoon(insuranceDays)) ...[
              const SizedBox(height: 14),
              const Divider(height: 1),
              const SizedBox(height: 12),
              if (_isExpiringSoon(registrationDays))
                _ExpiryChip(label: 'Registration', days: registrationDays!.toInt()),
              if (_isExpiringSoon(insuranceDays)) ...[
                const SizedBox(height: 6),
                _ExpiryChip(label: 'Insurance', days: insuranceDays!.toInt()),
              ],
            ],
          ],
        ),
      ),
    );
  }

  static bool _isExpiringSoon(num? days) => days != null && days <= 60;

  static IconData _iconFor(String? type) => switch (type) {
        'motorcycle' => LucideIcons.bike,
        'truck' || 'trailer' => LucideIcons.truck,
        'van' => LucideIcons.busFront,
        _ => LucideIcons.car,
      };
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value, this.valueColor});

  final String label;
  final String value;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: Theme.of(context).textTheme.labelSmall?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w600,
                color: valueColor,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
        ),
      ],
    );
  }
}

class _ExpiryChip extends StatelessWidget {
  const _ExpiryChip({required this.label, required this.days});

  final String label;
  final int days;

  @override
  Widget build(BuildContext context) {
    final isOverdue = days < 0;
    final colour = isOverdue ? Theme.of(context).colorScheme.error : context.fipColors.warning;

    return Row(
      children: [
        Icon(isOverdue ? LucideIcons.circleAlert : LucideIcons.clock, size: 14, color: colour),
        const SizedBox(width: 8),
        Text(
          isOverdue
              ? '$label expired ${days.abs()} days ago'
              : '$label expires in $days days',
          style: TextStyle(fontSize: 12, color: colour),
        ),
      ],
    );
  }
}

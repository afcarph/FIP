import 'package:flutter/material.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../features/fleet/data/fleet_models.dart';
import 'status_badge.dart';

/// One vehicle, as it appears in every list in the app.
///
/// Ordered by what an operator scans for: which vehicle, what is it doing,
/// how much fuel, when did we last hear from it. The plate is the identifier
/// people actually use over a radio, so it leads even when a nickname exists.
class VehicleCard extends StatelessWidget {
  const VehicleCard({super.key, required this.vehicle, this.onTap});

  final FleetVehicle vehicle;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final status = vehicle.status;
    final level = vehicle.fuelPercentage;

    return FipCard(
      onTap: onTap,
      accent: status == FleetStatus.attention ? status.color(context) : null,
      padding: const EdgeInsets.all(FipSpace.md + 2),
      child: Column(
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 38,
                height: 38,
                decoration: BoxDecoration(
                  color: status.color(context).withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(FipRadius.control),
                ),
                child: Icon(Icons.local_shipping_rounded, size: 19, color: status.color(context)),
              ),
              const SizedBox(width: FipSpace.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      vehicle.plateNumber,
                      style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if (vehicle.displayName != null)
                      Text(
                        vehicle.displayName!,
                        style: FipType.caption(context),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                  ],
                ),
              ),
              StatusBadge.fleet(status, context, dense: true),
            ],
          ),
          const SizedBox(height: FipSpace.md),
          Row(
            children: [
              Expanded(
                flex: 3,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(
                          Icons.local_gas_station_rounded,
                          size: 13,
                          color: fuelColor(context, level),
                        ),
                        const SizedBox(width: 4),
                        Text(
                          level == null ? 'No reading' : '${level.toStringAsFixed(0)}%',
                          style: theme.textTheme.labelLarge?.copyWith(
                            fontWeight: FontWeight.w700,
                            color: fuelColor(context, level),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 5),
                    ClipRRect(
                      borderRadius: BorderRadius.circular(FipRadius.pill),
                      child: LinearProgressIndicator(
                        value: level == null ? 0 : (level / 100).clamp(0.0, 1.0),
                        minHeight: 4,
                        backgroundColor: scheme.outlineVariant,
                        valueColor: AlwaysStoppedAnimation(fuelColor(context, level)),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: FipSpace.lg),
              Expanded(
                flex: 2,
                child: _Stat(
                  label: 'Efficiency',
                  value: vehicle.efficiency == null
                      ? '—'
                      : '${vehicle.efficiency!.toStringAsFixed(1)} km/L',
                ),
              ),
              Expanded(
                flex: 2,
                child: _Stat(label: 'Last seen', value: vehicle.lastSeenLabel),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});

  final String label;
  final String value;

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
          style: Theme.of(
            context,
          ).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ],
    );
  }
}

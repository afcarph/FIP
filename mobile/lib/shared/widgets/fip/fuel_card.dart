import 'package:flutter/material.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';

/// A tank reading: the level, what it means, and how old it is.
///
/// The age is not decoration. A tank at 12% measured four days ago is a
/// different situation from one measured ten minutes ago, and showing the
/// percentage without the timestamp invites acting on a stale number.
class FuelCard extends StatelessWidget {
  const FuelCard({
    super.key,
    required this.percentage,
    this.litres,
    this.capacity,
    this.recordedLabel,
    this.isStale = false,
    this.onTap,
  });

  final double? percentage;
  final double? litres;
  final double? capacity;

  /// Human phrasing of when the reading was taken, e.g. "2 hours ago".
  final String? recordedLabel;
  final bool isStale;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final color = fuelColor(context, percentage);
    final pct = percentage;

    return FipCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.local_gas_station_rounded, size: 16, color: color),
              const SizedBox(width: 6),
              Text('FUEL LEVEL', style: FipType.eyebrow(context)),
              const Spacer(),
              if (isStale)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                  decoration: BoxDecoration(
                    color: scheme.onSurfaceVariant.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(FipRadius.pill),
                  ),
                  child: Text(
                    'Stale',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: FipSpace.md),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                pct == null ? '—' : pct.toStringAsFixed(0),
                style: FipType.kpi(context).copyWith(color: color),
              ),
              if (pct != null)
                Text(
                  '%',
                  style: theme.textTheme.titleMedium?.copyWith(
                    color: color,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              const Spacer(),
              if (litres != null)
                Text(
                  capacity != null
                      ? '${litres!.toStringAsFixed(0)} / ${capacity!.toStringAsFixed(0)} L'
                      : '${litres!.toStringAsFixed(0)} L',
                  style: FipType.caption(context),
                ),
            ],
          ),
          const SizedBox(height: FipSpace.md),
          ClipRRect(
            borderRadius: BorderRadius.circular(FipRadius.pill),
            child: LinearProgressIndicator(
              value: pct == null ? 0 : (pct / 100).clamp(0.0, 1.0),
              minHeight: 7,
              backgroundColor: scheme.outlineVariant,
              valueColor: AlwaysStoppedAnimation(color),
            ),
          ),
          if (recordedLabel != null) ...[
            const SizedBox(height: FipSpace.sm),
            Text(
              pct == null ? 'No reading recorded' : 'Measured $recordedLabel',
              style: theme.textTheme.labelSmall?.copyWith(color: scheme.onSurfaceVariant),
            ),
          ],
        ],
      ),
    );
  }
}

import 'package:flutter/material.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../features/fleet/data/fleet_models.dart';
import 'status_badge.dart';

/// One alert, sized to be scanned in a queue.
///
/// The severity rule runs down the left edge rather than tinting the card.
/// A screen of amber panels is exhausting and stops conveying urgency by the
/// third one; a coloured edge stays legible at any density.
class AlertCard extends StatelessWidget {
  const AlertCard({super.key, required this.alert, this.onTap});

  final FleetAlert alert;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = alert.severity.color(context);

    return FipCard(
      onTap: onTap,
      accent: color,
      padding: const EdgeInsets.all(FipSpace.md + 2),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  alert.title,
                  style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                ),
              ),
              const SizedBox(width: FipSpace.sm),
              StatusBadge.severity(alert.severity, context, dense: true),
            ],
          ),
          const SizedBox(height: FipSpace.sm),
          Row(
            children: [
              if (alert.vehiclePlate != null) ...[
                Icon(
                  Icons.local_shipping_rounded,
                  size: 13,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                const SizedBox(width: 4),
                Text(
                  alert.vehiclePlate!,
                  style: theme.textTheme.labelMedium?.copyWith(fontWeight: FontWeight.w600),
                ),
                const SizedBox(width: FipSpace.md),
              ],
              Icon(Icons.schedule_rounded, size: 13, color: theme.colorScheme.onSurfaceVariant),
              const SizedBox(width: 4),
              Flexible(
                child: Text(
                  alert.detectedLabel,
                  style: FipType.caption(context),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (alert.status == 'open') ...[
                const Spacer(),
                Container(
                  width: 6,
                  height: 6,
                  decoration: BoxDecoration(color: color, shape: BoxShape.circle),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

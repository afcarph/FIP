import 'package:flutter/material.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';

/// A small coloured pill stating one operational fact.
///
/// Tinted background rather than a solid fill: a list of twelve vehicles with
/// twelve saturated badges is unreadable, and the badge is a label, not the
/// most important thing in the row.
class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.label, required this.color, this.icon, this.dense = false});

  StatusBadge.fleet(FleetStatus status, BuildContext context, {super.key, this.dense = false})
    : label = status.label,
      color = status.color(context),
      icon = status.icon;

  StatusBadge.severity(FipSeverity severity, BuildContext context, {super.key, this.dense = false})
    : label = severity.label,
      color = severity.color(context),
      icon = null;

  final String label;
  final Color color;
  final IconData? icon;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: dense ? 7 : 9, vertical: dense ? 2 : 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.13),
        borderRadius: BorderRadius.circular(FipRadius.pill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: dense ? 11 : 13, color: color),
            const SizedBox(width: 4),
          ],
          Text(
            label,
            style: (dense
                    ? Theme.of(context).textTheme.labelSmall
                    : Theme.of(context).textTheme.labelMedium)
                ?.copyWith(color: color, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
  }
}

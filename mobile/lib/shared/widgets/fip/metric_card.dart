import 'package:flutter/material.dart';

import '../../../core/theme/fip_spacing.dart';

/// A single figure with its unit and label.
///
/// The number is the largest thing in the card and the unit rides beside it
/// rather than inside the value string, so `1,240` and `km` can be styled
/// differently without the caller assembling a sentence.
class MetricCard extends StatelessWidget {
  const MetricCard({
    super.key,
    required this.label,
    required this.value,
    this.unit,
    this.icon,
    this.tone,
    this.hint,
    this.onTap,
    this.compact = false,
  });

  final String label;

  /// Already formatted. An em dash is the right value for "not measured" —
  /// a metric that was never taken did not take the value zero.
  final String value;
  final String? unit;
  final IconData? icon;

  /// Colours the figure. Left null the value uses the default ink, which is
  /// correct for neutral measurements.
  final Color? tone;

  final String? hint;
  final VoidCallback? onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return FipCard(
      onTap: onTap,
      padding: EdgeInsets.all(compact ? FipSpace.md : FipSpace.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              if (icon != null) ...[
                Icon(icon, size: 15, color: theme.colorScheme.onSurfaceVariant),
                const SizedBox(width: 6),
              ],
              Expanded(
                child: Text(
                  label,
                  style: FipType.caption(context),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          const SizedBox(height: FipSpace.sm),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Flexible(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    value,
                    style: (compact ? FipType.kpiSmall(context) : FipType.kpi(context)).copyWith(
                      color: tone,
                    ),
                  ),
                ),
              ),
              if (unit != null) ...[
                const SizedBox(width: 3),
                Text(
                  unit!,
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ],
          ),
          if (hint != null) ...[
            const SizedBox(height: 2),
            Text(
              hint!,
              style: theme.textTheme.labelSmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ],
      ),
    );
  }
}

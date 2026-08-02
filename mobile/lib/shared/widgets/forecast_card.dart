import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';

/// The weekly forecast, shown with its confidence.
///
/// The confidence bar is not decoration: a 55% call and an 85% call warrant
/// different behaviour from the user, and a bare number reads as more
/// precision than the model actually has.
class ForecastCard extends StatelessWidget {
  const ForecastCard({
    required this.fuelType,
    required this.direction,
    required this.changeAmount,
    required this.confidence,
    this.narrative,
    this.effectiveWeek,
    super.key,
  });

  final String fuelType;
  final String direction;
  final double changeAmount;
  final double confidence;
  final String? narrative;
  final String? effectiveWeek;

  @override
  Widget build(BuildContext context) {
    final colors = context.fipColors;
    final scheme = Theme.of(context).colorScheme;

    final (icon, tint, verb) = switch (direction) {
      'increase' => (LucideIcons.trendingUp, colors.priceUp, 'Increase expected'),
      'rollback' => (LucideIcons.trendingDown, colors.priceDown, 'Rollback expected'),
      _ => (LucideIcons.minus, colors.priceFlat, 'No change expected'),
    };

    final confidencePct = (confidence * 100).round();
    final confidenceLabel = confidence >= 0.85
        ? 'High'
        : confidence >= 0.65
            ? 'Moderate'
            : 'Low';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    fuelType,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: tint.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(icon, size: 11, color: tint),
                      const SizedBox(width: 4),
                      Text(
                        '$confidenceLabel confidence',
                        style: TextStyle(fontSize: 10, color: tint, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
              ],
            ),

            const SizedBox(height: 12),

            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: [
                Text(
                  direction == 'no_change'
                      ? '—'
                      : '${changeAmount > 0 ? '+' : ''}${Formatters.currency(changeAmount)}',
                  style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                        fontWeight: FontWeight.bold,
                        color: tint,
                        fontFeatures: const [FontFeature.tabularFigures()],
                      ),
                ),
                if (direction != 'no_change') ...[
                  const SizedBox(width: 6),
                  Text(
                    'per litre',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                  ),
                ],
              ],
            ),

            Text(verb, style: Theme.of(context).textTheme.bodySmall),

            const SizedBox(height: 10),

            // Confidence as a proportion, not just a number.
            Semantics(
              label: 'Model confidence $confidencePct percent',
              child: ClipRRect(
                borderRadius: BorderRadius.circular(999),
                child: LinearProgressIndicator(
                  value: confidence,
                  minHeight: 5,
                  backgroundColor: scheme.surfaceContainerHighest,
                  valueColor: AlwaysStoppedAnimation(
                    confidence >= 0.85
                        ? colors.priceDown
                        : confidence >= 0.65
                            ? scheme.primary
                            : colors.warning,
                  ),
                ),
              ),
            ),

            if (narrative != null) ...[
              const SizedBox(height: 10),
              Text(
                narrative!,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                      height: 1.35,
                    ),
                maxLines: 3,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

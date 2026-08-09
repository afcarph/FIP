import 'package:flutter/material.dart';

import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';

/// Fleet Pulse — the signature FIP component.
///
/// One glance, four numbers: how much of the fleet is reporting, how much is
/// actually moving, how much is parked, and how much needs somebody. It leads
/// every FIP home screen because it is the question an operator opens the app
/// to answer, and everything below it is detail.
///
/// Attention is styled apart from the other three. The first three are a
/// census; the fourth is a to-do list, and flattening them into one row of
/// identical tiles would bury the only number that asks for action.
class FleetPulse extends StatelessWidget {
  const FleetPulse({
    super.key,
    required this.active,
    required this.moving,
    required this.stopped,
    required this.attention,
    this.total,
    this.onAttentionTap,
  });

  /// Vehicles that have reported recently.
  final int active;
  final int moving;
  final int stopped;

  /// Vehicles with an open alert, a low tank, or no recent contact.
  final int attention;

  /// Fleet size, used to frame `active` as a share rather than a bare count.
  final int? total;

  final VoidCallback? onAttentionTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final hasAttention = attention > 0;

    return Container(
      padding: const EdgeInsets.all(FipSpace.lg),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(FipRadius.card),
        border: Border.all(color: scheme.outlineVariant),
        // A restrained vertical wash in the brand navy. Enough to mark this as
        // the primary surface without turning it into a marketing panel.
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: theme.brightness == Brightness.dark
              ? [const Color(0xFF10243D), scheme.surfaceContainerHighest]
              : [const Color(0xFFF2F6FB), Colors.white],
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 6,
                height: 6,
                decoration: BoxDecoration(
                  color: active > 0 ? FipBrand.green : scheme.outline,
                  shape: BoxShape.circle,
                ),
              ),
              const SizedBox(width: FipSpace.sm),
              Text('FLEET PULSE', style: FipType.eyebrow(context)),
              const Spacer(),
              if (total != null)
                Text(
                  '$active of $total reporting',
                  style: theme.textTheme.labelSmall?.copyWith(color: scheme.onSurfaceVariant),
                ),
            ],
          ),
          const SizedBox(height: FipSpace.lg),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: _Metric(value: active, label: 'Active', color: scheme.onSurface)),
              _Divider(),
              Expanded(
                child: _Metric(
                  value: moving,
                  label: 'Moving',
                  color: FleetStatus.moving.color(context),
                ),
              ),
              _Divider(),
              Expanded(
                child: _Metric(
                  value: stopped,
                  label: 'Stopped',
                  color: FleetStatus.stopped.color(context),
                ),
              ),
            ],
          ),
          const SizedBox(height: FipSpace.lg),
          _AttentionRow(
            attention: attention,
            hasAttention: hasAttention,
            onTap: onAttentionTap,
          ),
        ],
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.value, required this.label, required this.color});

  final int value;
  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('$value', style: FipType.kpi(context).copyWith(color: color)),
        const SizedBox(height: 2),
        Text(label, style: FipType.caption(context)),
      ],
    );
  }
}

class _Divider extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      width: 1,
      height: 34,
      margin: const EdgeInsets.symmetric(horizontal: FipSpace.md),
      color: Theme.of(context).colorScheme.outlineVariant,
    );
  }
}

class _AttentionRow extends StatelessWidget {
  const _AttentionRow({required this.attention, required this.hasAttention, this.onTap});

  final int attention;
  final bool hasAttention;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = hasAttention
        ? FleetStatus.attention.color(context)
        : FleetStatus.moving.color(context);

    return Material(
      color: color.withValues(alpha: 0.10),
      borderRadius: BorderRadius.circular(FipRadius.control),
      child: InkWell(
        onTap: hasAttention ? onTap : null,
        borderRadius: BorderRadius.circular(FipRadius.control),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: FipSpace.md, vertical: FipSpace.md),
          child: Row(
            children: [
              Icon(
                hasAttention ? Icons.warning_amber_rounded : Icons.check_circle_outline_rounded,
                size: 18,
                color: color,
              ),
              const SizedBox(width: FipSpace.sm),
              Expanded(
                child: Text(
                  hasAttention
                      ? '$attention ${attention == 1 ? 'vehicle needs' : 'vehicles need'} attention'
                      : 'Nothing needs attention',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                    color: color,
                  ),
                ),
              ),
              if (hasAttention) Icon(Icons.chevron_right_rounded, size: 18, color: color),
            ],
          ),
        ),
      ),
    );
  }
}

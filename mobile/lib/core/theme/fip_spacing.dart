import 'package:flutter/material.dart';

/// Spacing, radii and the KPI type scale.
///
/// One 4pt rhythm, applied everywhere. The values are named for their job
/// rather than their size, so a screen that needs "the gap between cards"
/// cannot quietly drift to 13.
class FipSpace {
  const FipSpace._();

  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 20;
  static const double xxl = 28;

  /// Horizontal page margin. Wide enough to breathe on a phone, narrow enough
  /// that a four-up KPI row still fits without shrinking the numbers.
  static const double page = 20;

  /// Gap between stacked cards.
  static const double gap = 12;
}

class FipRadius {
  const FipRadius._();

  /// Restrained on purpose. Enterprise tools are read for hours; heavy
  /// rounding reads as consumer and costs usable width in every card.
  static const double card = 14;
  static const double control = 10;
  static const double pill = 999;
}

/// Type treatments that Material's scale has no slot for.
class FipType {
  const FipType._();

  /// The large figure on a KPI card. Tabular so a column of numbers does not
  /// shift as the digits change.
  static TextStyle kpi(BuildContext context) =>
      Theme.of(context).textTheme.headlineMedium!.copyWith(
        fontWeight: FontWeight.w700,
        fontFeatures: const [FontFeature.tabularFigures()],
        height: 1.1,
      );

  static TextStyle kpiSmall(BuildContext context) =>
      Theme.of(context).textTheme.titleLarge!.copyWith(
        fontWeight: FontWeight.w700,
        fontFeatures: const [FontFeature.tabularFigures()],
        height: 1.1,
      );

  /// The quiet label under a figure.
  static TextStyle caption(BuildContext context) => Theme.of(
    context,
  ).textTheme.bodySmall!.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant);

  /// Small all-caps label for section eyebrows and badges.
  static TextStyle eyebrow(BuildContext context) =>
      Theme.of(context).textTheme.labelSmall!.copyWith(
        fontWeight: FontWeight.w700,
        letterSpacing: 0.8,
        color: Theme.of(context).colorScheme.onSurfaceVariant,
      );
}

/// The one card shell every FIP surface uses.
///
/// A single bordered container rather than Material elevation: shadows stack
/// badly in a dense list and cost contrast in dark mode.
class FipCard extends StatelessWidget {
  const FipCard({super.key, required this.child, this.padding, this.onTap, this.accent});

  final Widget child;
  final EdgeInsetsGeometry? padding;
  final VoidCallback? onTap;

  /// Optional left rule, used to carry a status colour without tinting the
  /// whole card.
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Material(
      color: scheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(FipRadius.card),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(FipRadius.card),
        // The accent rule is positioned over the card rather than being a
        // stretched Row child or a thicker left border. Both of those failed
        // for different reasons: CrossAxisAlignment.stretch needs a bounded
        // height and these cards live in slivers where it is unbounded, while
        // a non-uniform border cannot carry a borderRadius. A Stack takes its
        // size from the padded child, so the rule can fill that height without
        // anything needing to know it in advance.
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(FipRadius.card),
            border: Border.all(color: scheme.outlineVariant),
          ),
          child: Stack(
            children: [
              // Full width by construction. A Stack hands its children loose
              // constraints, so a Column shrank to its intrinsic width and sat
              // against the left edge — which made two side-by-side action
              // cards with different label lengths look mismatched.
              SizedBox(
                width: double.infinity,
                child: Padding(
                  padding: padding ?? const EdgeInsets.all(FipSpace.lg),
                  child: child,
                ),
              ),
              if (accent != null)
                Positioned(
                  left: 0,
                  top: 0,
                  bottom: 0,
                  width: 3,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: accent,
                      borderRadius: const BorderRadius.horizontal(
                        left: Radius.circular(FipRadius.card),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

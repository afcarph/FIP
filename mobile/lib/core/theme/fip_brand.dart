import 'package:flutter/material.dart';

/// The FIP brand, taken from the logo rather than invented.
///
/// [navy] and [green] are the averaged ink of `fip-mark-square.png` — the
/// droplet and the growth arrow. Every accent in the app descends from one of
/// them, which is what stops a fleet dashboard drifting into generic
/// Material blue.
class FipBrand {
  const FipBrand._();

  /// The droplet and nozzle.
  static const Color navy = Color(0xFF052F53);

  /// The growth arrow.
  static const Color green = Color(0xFF3E9A36);

  static const Color navyLight = Color(0xFF0E4477);
  static const Color greenLight = Color(0xFF56B84C);
}

/// Operational state, in the vocabulary a fleet operator uses.
///
/// Fleet status is not a mood — it drives whether somebody walks out to a
/// vehicle — so each state gets one colour used identically everywhere:
/// the pulse counters, the badges, the map pins and the list rows.
enum FleetStatus { moving, stopped, offline, attention }

extension FleetStatusStyle on FleetStatus {
  String get label => switch (this) {
    FleetStatus.moving => 'Moving',
    FleetStatus.stopped => 'Stopped',
    FleetStatus.offline => 'Offline',
    FleetStatus.attention => 'Attention',
  };

  Color color(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return switch (this) {
      FleetStatus.moving => isDark ? FipBrand.greenLight : FipBrand.green,
      FleetStatus.stopped => isDark ? const Color(0xFF7C9CBF) : const Color(0xFF4A6E96),
      // Deliberately grey rather than red. A vehicle out of contact is a gap
      // in knowledge, not a fault, and colouring it as an error trains people
      // to ignore the colour that means something is actually wrong.
      FleetStatus.offline => isDark ? const Color(0xFF64748B) : const Color(0xFF94A3B8),
      FleetStatus.attention => isDark ? const Color(0xFFF87171) : const Color(0xFFDC2626),
    };
  }

  IconData get icon => switch (this) {
    FleetStatus.moving => Icons.navigation_rounded,
    FleetStatus.stopped => Icons.pause_circle_outline_rounded,
    FleetStatus.offline => Icons.cloud_off_rounded,
    FleetStatus.attention => Icons.warning_amber_rounded,
  };
}

/// Severity of a fuel or tracking alert, mapped to colour once.
enum FipSeverity { critical, high, medium, low }

extension FipSeverityStyle on FipSeverity {
  static FipSeverity parse(String? value) => switch (value?.toLowerCase()) {
    'critical' => FipSeverity.critical,
    'high' => FipSeverity.high,
    'medium' => FipSeverity.medium,
    _ => FipSeverity.low,
  };

  String get label => switch (this) {
    FipSeverity.critical => 'Critical',
    FipSeverity.high => 'High',
    FipSeverity.medium => 'Medium',
    FipSeverity.low => 'Low',
  };

  Color color(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return switch (this) {
      FipSeverity.critical => isDark ? const Color(0xFFF87171) : const Color(0xFFDC2626),
      FipSeverity.high => isDark ? const Color(0xFFFB923C) : const Color(0xFFEA580C),
      FipSeverity.medium => isDark ? const Color(0xFFFBBF24) : const Color(0xFFD97706),
      FipSeverity.low => isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B),
    };
  }
}

/// Fuel level bands, matching the server's own thresholds so the phone and
/// the API never disagree about what "low" means.
Color fuelColor(BuildContext context, double? percentage) {
  final isDark = Theme.of(context).brightness == Brightness.dark;

  if (percentage == null) {
    return isDark ? const Color(0xFF64748B) : const Color(0xFF94A3B8);
  }
  if (percentage <= 10) return isDark ? const Color(0xFFF87171) : const Color(0xFFDC2626);
  if (percentage <= 25) return isDark ? const Color(0xFFFBBF24) : const Color(0xFFD97706);

  return isDark ? FipBrand.greenLight : FipBrand.green;
}

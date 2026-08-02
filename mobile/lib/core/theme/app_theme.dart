import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';

/// The app's visual language, mirroring the web design tokens so the two
/// clients feel like one product.
///
/// Both themes are built from the same [ColorScheme] shape, which is what
/// lets every widget be written once. Semantic colours that Material does not
/// model — price up, price down — live in [FipColors] and are attached as a
/// [ThemeExtension] so they are reachable from `Theme.of(context)`.
class AppTheme {
  const AppTheme._();

  static const Color _primaryLight = Color(0xFF2563EB);
  static const Color _primaryDark = Color(0xFF3B82F6);

  static ThemeData get light => _build(Brightness.light);
  static ThemeData get dark => _build(Brightness.dark);

  static ThemeData _build(Brightness brightness) {
    final isDark = brightness == Brightness.dark;

    final scheme = ColorScheme.fromSeed(
      seedColor: isDark ? _primaryDark : _primaryLight,
      brightness: brightness,
    ).copyWith(
      surface: isDark ? const Color(0xFF0F1729) : const Color(0xFFF8FAFC),
      surfaceContainerHighest: isDark ? const Color(0xFF1A2437) : Colors.white,
      outlineVariant: isDark ? const Color(0xFF2A3A52) : const Color(0xFFE2E8F0),
    );

    final textTheme = GoogleFonts.interTextTheme(
      isDark ? ThemeData.dark().textTheme : ThemeData.light().textTheme,
    ).apply(
      bodyColor: scheme.onSurface,
      displayColor: scheme.onSurface,
    );

    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: scheme.surface,
      textTheme: textTheme,

      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        foregroundColor: scheme.onSurface,
        elevation: 0,
        scrolledUnderElevation: 0.5,
        centerTitle: false,
        titleTextStyle: textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
        systemOverlayStyle: isDark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
      ),

      cardTheme: CardTheme(
        color: scheme.surfaceContainerHighest,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(color: scheme.outlineVariant),
        ),
      ),

      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          // 48dp keeps every primary action above the minimum touch target.
          minimumSize: const Size(64, 48),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
        ),
      ),

      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(64, 48),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          side: BorderSide(color: scheme.outlineVariant),
        ),
      ),

      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: isDark ? const Color(0xFF162034) : Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: scheme.outlineVariant),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: scheme.outlineVariant),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: scheme.primary, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: scheme.error),
        ),
      ),

      chipTheme: ChipThemeData(
        backgroundColor: isDark ? const Color(0xFF1E293B) : const Color(0xFFF1F5F9),
        selectedColor: scheme.primary.withValues(alpha: 0.15),
        labelStyle: textTheme.labelMedium,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
        side: BorderSide.none,
      ),

      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: scheme.surfaceContainerHighest,
        elevation: 0,
        height: 68,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        indicatorColor: scheme.primary.withValues(alpha: 0.15),
      ),

      dividerTheme: DividerThemeData(color: scheme.outlineVariant, thickness: 1, space: 1),

      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),

      extensions: <ThemeExtension<dynamic>>[
        isDark ? FipColors.dark : FipColors.light,
      ],
    );
  }
}

/// Semantic colours Material has no slot for.
///
/// Price movement is the app's most repeated signal, so it gets first-class
/// treatment rather than scattered hard-coded hex values.
@immutable
class FipColors extends ThemeExtension<FipColors> {
  const FipColors({
    required this.priceUp,
    required this.priceDown,
    required this.priceFlat,
    required this.warning,
    required this.chartSeries,
  });

  /// A rising price is bad news for a motorist, so it takes the warm colour.
  final Color priceUp;
  final Color priceDown;
  final Color priceFlat;
  final Color warning;
  final List<Color> chartSeries;

  static const light = FipColors(
    priceUp: Color(0xFFDC2626),
    priceDown: Color(0xFF16A34A),
    priceFlat: Color(0xFF64748B),
    warning: Color(0xFFF59E0B),
    chartSeries: [
      Color(0xFF2563EB),
      Color(0xFF16A34A),
      Color(0xFFF59E0B),
      Color(0xFF9333EA),
      Color(0xFF0EA5E9),
      Color(0xFFDB2777),
    ],
  );

  // Saturation is lifted in dark mode; the light hues read as muddy on a
  // dark surface.
  static const dark = FipColors(
    priceUp: Color(0xFFF87171),
    priceDown: Color(0xFF4ADE80),
    priceFlat: Color(0xFF94A3B8),
    warning: Color(0xFFFBBF24),
    chartSeries: [
      Color(0xFF60A5FA),
      Color(0xFF4ADE80),
      Color(0xFFFBBF24),
      Color(0xFFC084FC),
      Color(0xFF38BDF8),
      Color(0xFFF472B6),
    ],
  );

  /// Colour for a signed movement, with a dead band so sub-centavo noise
  /// does not read as a change.
  Color forChange(num? change) {
    if (change == null || change.abs() < 0.001) return priceFlat;
    return change > 0 ? priceUp : priceDown;
  }

  @override
  FipColors copyWith({
    Color? priceUp,
    Color? priceDown,
    Color? priceFlat,
    Color? warning,
    List<Color>? chartSeries,
  }) {
    return FipColors(
      priceUp: priceUp ?? this.priceUp,
      priceDown: priceDown ?? this.priceDown,
      priceFlat: priceFlat ?? this.priceFlat,
      warning: warning ?? this.warning,
      chartSeries: chartSeries ?? this.chartSeries,
    );
  }

  @override
  FipColors lerp(FipColors? other, double t) {
    if (other == null) return this;

    return FipColors(
      priceUp: Color.lerp(priceUp, other.priceUp, t)!,
      priceDown: Color.lerp(priceDown, other.priceDown, t)!,
      priceFlat: Color.lerp(priceFlat, other.priceFlat, t)!,
      warning: Color.lerp(warning, other.warning, t)!,
      chartSeries: t < 0.5 ? chartSeries : other.chartSeries,
    );
  }
}

extension FipColorsX on BuildContext {
  FipColors get fipColors => Theme.of(this).extension<FipColors>()!;
}

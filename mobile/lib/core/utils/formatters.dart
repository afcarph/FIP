import 'package:intl/intl.dart';

/// Presentation helpers, matching the web client so the same figure never
/// appears differently on the two platforms.
class Formatters {
  const Formatters._();

  static final NumberFormat _peso = NumberFormat.currency(
    locale: 'en_PH',
    symbol: '₱',
    decimalDigits: 2,
  );
  static final NumberFormat _pesoWhole = NumberFormat.currency(
    locale: 'en_PH',
    symbol: '₱',
    decimalDigits: 0,
  );
  // Pin the compact precision to one fraction digit, matching the web client's
  // `maximumFractionDigits: 1`. intl's own default is three *significant*
  // digits, which renders 1,250,000 as 1.25M where the web shows 1.3M — and
  // that default has shifted between intl releases.
  static final NumberFormat _compact =
      NumberFormat.compact(locale: 'en_PH')
        ..significantDigitsInUse = false
        ..maximumFractionDigits = 1;

  static const String _placeholder = '—';

  static String currency(num? value, {int decimals = 2}) {
    if (value == null) return _placeholder;
    return decimals == 0 ? _pesoWhole.format(value) : _peso.format(value);
  }

  /// Compact form for dashboard tiles, but only once the exact figure stops
  /// fitting comfortably.
  static String compactCurrency(num? value) {
    if (value == null) return _placeholder;
    if (value.abs() < 10000) return _pesoWhole.format(value);
    return '₱${_compact.format(value)}';
  }

  static String number(num? value, {int decimals = 0}) {
    if (value == null) return _placeholder;

    return NumberFormat.decimalPatternDigits(
      locale: 'en_PH',
      decimalDigits: decimals,
    ).format(value);
  }

  static String litres(num? value) =>
      value == null ? _placeholder : '${number(value, decimals: 1)} L';

  static String distance(num? value) =>
      value == null ? _placeholder : '${number(value, decimals: 1)} km';

  static String efficiency(num? value) =>
      value == null ? _placeholder : '${number(value, decimals: 1)} km/L';

  /// Signed percentage — the sign is always explicit on a movement figure.
  static String percent(num? value, {int decimals = 1}) {
    if (value == null) return _placeholder;
    return '${value > 0 ? '+' : ''}${value.toStringAsFixed(decimals)}%';
  }

  static String date(DateTime? value) =>
      value == null ? _placeholder : DateFormat('d MMM yyyy').format(value);

  static String dateTime(DateTime? value) =>
      value == null ? _placeholder : DateFormat('d MMM, h:mm a').format(value);

  /// Human relative time. Falls back to an absolute date beyond a week,
  /// where "23 days ago" is less useful than the date itself.
  static String relative(DateTime? value) {
    if (value == null) return _placeholder;

    final difference = DateTime.now().difference(value);

    if (difference.inSeconds.abs() < 60) return 'just now';
    if (difference.inMinutes.abs() < 60) return '${difference.inMinutes.abs()} min ago';
    if (difference.inHours.abs() < 24) return '${difference.inHours.abs()} hr ago';
    if (difference.inDays.abs() < 7) return '${difference.inDays.abs()} d ago';

    return date(value);
  }

  static String initials(String? name) {
    if (name == null || name.trim().isEmpty) return '?';

    final parts = name.trim().split(RegExp(r'\s+'));

    return parts.take(2).map((part) => part[0].toUpperCase()).join();
  }

  /// Direction of a price movement, with a dead band around zero.
  static String trend(num? change) {
    if (change == null || change.abs() < 0.001) return 'flat';
    return change > 0 ? 'up' : 'down';
  }
}

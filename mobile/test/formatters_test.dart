import 'package:fip_mobile/core/utils/formatters.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('currency', () {
    test('formats pesos with two decimals', () {
      expect(Formatters.currency(58.5), '₱58.50');
      expect(Formatters.currency(1234.567), '₱1,234.57');
    });

    test('shows an em dash rather than "null" for missing values', () {
      expect(Formatters.currency(null), '—');
    });

    test('compacts only once the exact figure stops fitting', () {
      expect(Formatters.compactCurrency(2500), '₱2,500');
      expect(Formatters.compactCurrency(1250000), contains('1.3M'));
      expect(Formatters.compactCurrency(null), '—');
    });
  });

  group('units', () {
    test('appends the right unit', () {
      expect(Formatters.litres(45.67), '45.7 L');
      expect(Formatters.distance(1234.5), '1,234.5 km');
      expect(Formatters.efficiency(12.34), '12.3 km/L');
    });

    test('handles nulls without throwing', () {
      expect(Formatters.litres(null), '—');
      expect(Formatters.distance(null), '—');
      expect(Formatters.efficiency(null), '—');
    });
  });

  group('percent', () {
    test('always carries an explicit sign', () {
      expect(Formatters.percent(12.34), '+12.3%');
      expect(Formatters.percent(-5), '-5.0%');
      expect(Formatters.percent(0), '0.0%');
    });
  });

  group('trend', () {
    test('applies a dead band so sub-centavo noise reads as flat', () {
      expect(Formatters.trend(0.5), 'up');
      expect(Formatters.trend(-0.5), 'down');
      expect(Formatters.trend(0), 'flat');
      expect(Formatters.trend(0.0001), 'flat');
      expect(Formatters.trend(null), 'flat');
    });
  });

  group('initials', () {
    test('takes the first letter of up to two words', () {
      expect(Formatters.initials('Ella Santos'), 'ES');
      expect(Formatters.initials('Jomar Dela Cruz'), 'JD');
      expect(Formatters.initials('Cher'), 'C');
      expect(Formatters.initials(null), '?');
      expect(Formatters.initials('   '), '?');
    });
  });

  group('relative time', () {
    test('describes recent moments in words', () {
      expect(Formatters.relative(DateTime.now().subtract(const Duration(seconds: 20))), 'just now');
      expect(Formatters.relative(DateTime.now().subtract(const Duration(minutes: 30))), '30 min ago');
      expect(Formatters.relative(DateTime.now().subtract(const Duration(hours: 5))), '5 hr ago');
    });

    test('falls back to an absolute date beyond a week', () {
      final old = DateTime.now().subtract(const Duration(days: 30));

      // "30 days ago" is less useful than the date itself.
      expect(Formatters.relative(old), Formatters.date(old));
    });
  });
}

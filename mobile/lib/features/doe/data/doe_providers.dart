import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../shared/providers/app_providers.dart';
import 'doe_models.dart';

/// Providers over the DOE price monitoring endpoints.
///
/// Every one passes `skipAuth: true` — these are the department's published
/// figures and the API serves them without a token. Sending one would be
/// harmless but would make the screens fail for a signed-out tester, which is
/// most of them during UAT.

/// Filters a search or listing can carry. Empty values are pruned by the
/// client, so an unset field never becomes `?region=`.
class DoeQuery {
  const DoeQuery({this.region, this.area, this.brand, this.fuelCode, this.perPage = 50});

  final String? region;
  final String? area;
  final String? brand;
  final String? fuelCode;
  final int perPage;

  Map<String, dynamic> toParams() => {
    if (region != null && region!.isNotEmpty) 'region': region,
    if (area != null && area!.isNotEmpty) 'area': area,
    if (brand != null && brand!.isNotEmpty) 'brand': brand,
    if (fuelCode != null && fuelCode!.isNotEmpty) 'fuel_code': fuelCode,
    'per_page': perPage,
  };

  /// Riverpod families key on equality, so two identical queries share a fetch
  /// instead of issuing one request each.
  @override
  bool operator ==(Object other) =>
      other is DoeQuery &&
      other.region == region &&
      other.area == area &&
      other.brand == brand &&
      other.fuelCode == fuelCode &&
      other.perPage == perPage;

  @override
  int get hashCode => Object.hash(region, area, brand, fuelCode, perPage);
}

/// Ingestion health, and the counts the home screen shows.
final doeHealthProvider = FutureProvider<ImportHealth>((ref) async {
  final data = await ref
      .watch(apiClientProvider)
      .get<Map<String, dynamic>>('/fuel/imports', skipAuth: true);

  return ImportHealth.fromJson(data);
});

/// Prices from the most recent report per region.
final doeLatestProvider = FutureProvider.family<List<DoePrice>, DoeQuery>((ref, query) async {
  final data = await ref
      .watch(apiClientProvider)
      .get<List<dynamic>>('/fuel/latest', query: query.toParams(), skipAuth: true);

  return data.whereType<Map<String, dynamic>>().map(DoePrice.fromJson).toList();
});

/// Search across area, brand and fuel type.
final doeSearchProvider = FutureProvider.family<List<DoePrice>, DoeQuery>((ref, query) async {
  final data = await ref
      .watch(apiClientProvider)
      .get<List<dynamic>>('/fuel/search', query: query.toParams(), skipAuth: true);

  return data.whereType<Map<String, dynamic>>().map(DoePrice.fromJson).toList();
});

/// Every imported report, newest week first.
final doeReportsProvider = FutureProvider<List<DoeReport>>((ref) async {
  final data = await ref
      .watch(apiClientProvider)
      .get<List<dynamic>>('/fuel/reports', query: {'per_page': 100}, skipAuth: true);

  return data.whereType<Map<String, dynamic>>().map(DoeReport.fromJson).toList();
});

/// A weekly series for one grade.
final doeTrendProvider = FutureProvider.family<List<TrendPoint>, String>((ref, fuelCode) async {
  final data = await ref
      .watch(apiClientProvider)
      .get<List<dynamic>>(
        '/fuel/trends',
        query: {'fuel_code': fuelCode, 'weeks': 12},
        skipAuth: true,
      );

  return data.whereType<Map<String, dynamic>>().map(TrendPoint.fromJson).toList();
});

/// Brands the DOE monitors, for the search filter.
final doeBrandsProvider = FutureProvider<List<String>>((ref) async {
  final data = await ref
      .watch(apiClientProvider)
      .get<List<dynamic>>('/fuel/brands', skipAuth: true);

  return data
      .whereType<Map<String, dynamic>>()
      .map((entry) => entry['brand'] as String? ?? '')
      .where((brand) => brand.isNotEmpty)
      .toList();
});

/// The region the user is looking at. Drives Home and seeds Search.
final selectedRegionProvider = StateProvider<String?>((ref) => null);

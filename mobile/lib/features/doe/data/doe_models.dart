/// Models for the DOE price monitoring endpoints.
///
/// These are the department's own weekly publications: a min–max range per
/// city, product and brand, plus the common price it states outright. They are
/// not per-station pump prices — that is `/stations` and `/prices`, which the
/// platform maintains separately.
library;

class DoeReport {
  const DoeReport({
    required this.id,
    required this.region,
    required this.coverageStart,
    required this.coverageEnd,
    required this.coverageLabel,
    required this.areasCount,
    required this.rowsCount,
    this.monitoringDate,
    this.sourceUrl,
    this.quality,
  });

  final int id;
  final String region;
  final String coverageStart;
  final String coverageEnd;
  final String coverageLabel;
  final int areasCount;
  final int rowsCount;
  final String? monitoringDate;
  final String? sourceUrl;
  final double? quality;

  factory DoeReport.fromJson(Map<String, dynamic> json) => DoeReport(
    id: (json['id'] as num).toInt(),
    region: json['region'] as String? ?? '—',
    coverageStart: json['coverage_start'] as String? ?? '',
    coverageEnd: json['coverage_end'] as String? ?? '',
    coverageLabel: json['coverage_label'] as String? ?? '',
    areasCount: (json['areas_count'] as num?)?.toInt() ?? 0,
    rowsCount: (json['rows_count'] as num?)?.toInt() ?? 0,
    monitoringDate: json['monitoring_date'] as String?,
    sourceUrl: json['source_url'] as String?,
    quality: (json['quality'] as num?)?.toDouble(),
  );
}

class DoePrice {
  const DoePrice({
    required this.id,
    required this.area,
    required this.product,
    required this.isOverall,
    this.province,
    this.fuelCode,
    this.brand,
    this.minPrice,
    this.maxPrice,
    this.commonPrice,
    this.report,
  });

  final int id;
  final String area;
  final String product;

  /// True on the row carrying the area's overall range and common price, which
  /// the DOE prints as its own column rather than deriving.
  final bool isOverall;

  final String? province;
  final String? fuelCode;
  final String? brand;
  final double? minPrice;
  final double? maxPrice;
  final double? commonPrice;
  final DoeReport? report;

  factory DoePrice.fromJson(Map<String, dynamic> json) => DoePrice(
    id: (json['id'] as num).toInt(),
    area: json['area'] as String? ?? '—',
    product: json['product'] as String? ?? '—',
    isOverall: json['is_overall'] as bool? ?? json['brand'] == null,
    province: json['province'] as String?,
    fuelCode: json['fuel_code'] as String?,
    brand: json['brand'] as String?,
    minPrice: (json['min_price'] as num?)?.toDouble(),
    maxPrice: (json['max_price'] as num?)?.toDouble(),
    commonPrice: (json['common_price'] as num?)?.toDouble(),
    report:
        json['report'] is Map<String, dynamic>
            ? DoeReport.fromJson(json['report'] as Map<String, dynamic>)
            : null,
  );

  /// The published range as text. Never collapsed to a single figure — that
  /// would invent a number the DOE did not publish.
  String get rangeLabel {
    if (minPrice == null && maxPrice == null) return '—';
    if (minPrice != null && maxPrice != null && minPrice != maxPrice) {
      return '₱${minPrice!.toStringAsFixed(2)} – ₱${maxPrice!.toStringAsFixed(2)}';
    }
    return '₱${(minPrice ?? maxPrice)!.toStringAsFixed(2)}';
  }
}

class TrendPoint {
  const TrendPoint({
    required this.coverageStart,
    required this.lowest,
    required this.highest,
    required this.midpoint,
    this.common,
  });

  final String coverageStart;
  final double lowest;
  final double highest;

  /// Midpoint of the published range — the API says so in its own response.
  final double midpoint;
  final double? common;

  factory TrendPoint.fromJson(Map<String, dynamic> json) => TrendPoint(
    coverageStart: json['coverage_start'] as String? ?? '',
    lowest: (json['lowest'] as num?)?.toDouble() ?? 0,
    highest: (json['highest'] as num?)?.toDouble() ?? 0,
    midpoint: (json['midpoint'] as num?)?.toDouble() ?? 0,
    common: (json['common'] as num?)?.toDouble(),
  );
}

class ImportHealth {
  const ImportHealth({
    required this.reportsTotal,
    required this.pricesTotal,
    required this.regionsTotal,
    required this.latestReports,
    this.latestPublicationDate,
    this.lastRunStatus,
    this.lastRunAt,
  });

  final int reportsTotal;
  final int pricesTotal;
  final int regionsTotal;
  final List<DoeReport> latestReports;
  final String? latestPublicationDate;
  final String? lastRunStatus;
  final String? lastRunAt;

  List<String> get regions =>
      latestReports.map((report) => report.region).toSet().toList()..sort();

  factory ImportHealth.fromJson(Map<String, dynamic> json) {
    final lastRun = json['last_run'] as Map<String, dynamic>?;

    return ImportHealth(
      reportsTotal: (json['reports_total'] as num?)?.toInt() ?? 0,
      pricesTotal: (json['prices_total'] as num?)?.toInt() ?? 0,
      regionsTotal: (json['regions_total'] as num?)?.toInt() ?? 0,
      latestReports:
          (json['latest_reports'] as List<dynamic>? ?? [])
              .whereType<Map<String, dynamic>>()
              .map(DoeReport.fromJson)
              .toList(),
      latestPublicationDate: json['latest_publication_date'] as String?,
      lastRunStatus: lastRun?['status'] as String?,
      lastRunAt: lastRun?['started_at'] as String?,
    );
  }
}

/// The platform's fuel type codes, with the DOE's own product label.
///
/// Fixed by `fuel_types` rather than derived from whatever the current page
/// contains, so the filter options do not change as you filter.
const fuelTypeOptions = <({String code, String label})>[
  (code: 'gasoline_ron91', label: 'RON 91'),
  (code: 'gasoline_ron95', label: 'RON 95'),
  (code: 'gasoline_ron97', label: 'RON 97'),
  (code: 'gasoline_ron100', label: 'RON 100'),
  (code: 'diesel', label: 'Diesel'),
  (code: 'diesel_premium', label: 'Diesel Plus'),
  (code: 'kerosene', label: 'Kerosene'),
];

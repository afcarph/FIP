import '../../../core/theme/fip_brand.dart';
import '../../../core/utils/formatters.dart';

/// View models over the existing API payloads.
///
/// Nothing here invents a field. Every value is read from what
/// `/vehicles`, `/fleet/locations` and `/fleet/fraud-alerts` already return,
/// and anything the server has not measured stays null so the UI can say so
/// rather than print a confident zero.

class FleetVehicle {
  const FleetVehicle({
    required this.id,
    required this.plateNumber,
    this.displayName,
    this.fuelPercentage,
    this.fuelLitres,
    this.tankCapacity,
    this.fuelRecordedAt,
    this.fuelIsStale = false,
    this.fuelStatus,
    this.efficiency,
    this.baselineEfficiency,
    this.efficiencyDeviation,
    this.estimatedRangeKm,
    this.odometer,
    this.latitude,
    this.longitude,
    this.locationRecordedAt,
    this.hasOpenAlert = false,
  });

  final int id;
  final String plateNumber;
  final String? displayName;

  final double? fuelPercentage;
  final double? fuelLitres;
  final double? tankCapacity;
  final DateTime? fuelRecordedAt;
  final bool fuelIsStale;
  final String? fuelStatus;

  final double? efficiency;
  final double? baselineEfficiency;
  final double? efficiencyDeviation;
  final double? estimatedRangeKm;
  final double? odometer;

  final double? latitude;
  final double? longitude;
  final DateTime? locationRecordedAt;

  final bool hasOpenAlert;

  bool get hasLocation => latitude != null && longitude != null;

  /// Whether the vehicle has reported a position recently enough to be
  /// treated as live. Two hours is the app's own display threshold, not a
  /// server rule — it only decides what this screen calls "active".
  bool get isReporting =>
      locationRecordedAt != null &&
      DateTime.now().difference(locationRecordedAt!).inMinutes <= 120;

  /// A vehicle is "moving" only if it reported within the sampling interval.
  /// Anything older is a last known position, which is a different claim.
  bool get isMoving =>
      locationRecordedAt != null &&
      DateTime.now().difference(locationRecordedAt!).inMinutes <= 10;

  FleetStatus get status {
    if (hasOpenAlert || (fuelPercentage != null && fuelPercentage! <= 10)) {
      return FleetStatus.attention;
    }
    if (!isReporting) return FleetStatus.offline;

    return isMoving ? FleetStatus.moving : FleetStatus.stopped;
  }

  String get lastSeenLabel =>
      locationRecordedAt == null ? 'Never' : Formatters.relative(locationRecordedAt);

  String get fuelRecordedLabel =>
      fuelRecordedAt == null ? 'never' : Formatters.relative(fuelRecordedAt);

  static double? _num(dynamic v) => v == null ? null : (v as num).toDouble();

  static DateTime? _date(dynamic v) =>
      v == null ? null : DateTime.tryParse(v as String)?.toLocal();

  factory FleetVehicle.fromJson(Map<String, dynamic> json) {
    final fuel = json['fuel'] as Map<String, dynamic>? ?? const {};
    final efficiency = json['efficiency'] as Map<String, dynamic>? ?? const {};

    return FleetVehicle(
      id: json['id'] as int,
      plateNumber: (json['plate_number'] as String?) ?? 'Unknown',
      displayName: json['display_name'] as String? ?? json['nickname'] as String?,
      fuelPercentage: _num(fuel['current_percentage']),
      fuelLitres: _num(fuel['current_litres']),
      tankCapacity: _num(json['tank_capacity']),
      fuelRecordedAt: _date(fuel['recorded_at']),
      fuelIsStale: fuel['is_stale'] == true,
      fuelStatus: fuel['status'] as String?,
      efficiency: _num(efficiency['avg_km_per_litre']),
      baselineEfficiency: _num(efficiency['baseline_km_per_litre']),
      efficiencyDeviation: _num(efficiency['deviation_pct']),
      estimatedRangeKm: _num(efficiency['estimated_range_km']),
      odometer: _num(json['current_odometer']),
    );
  }

  /// Merge a live position from `/fleet/locations` onto the vehicle record.
  FleetVehicle withLocation(Map<String, dynamic> location) {
    return copyWith(
      latitude: _num(location['latitude']),
      longitude: _num(location['longitude']),
      locationRecordedAt: _date(location['recorded_at']),
    );
  }

  FleetVehicle copyWith({
    double? latitude,
    double? longitude,
    DateTime? locationRecordedAt,
    bool? hasOpenAlert,
  }) {
    return FleetVehicle(
      id: id,
      plateNumber: plateNumber,
      displayName: displayName,
      fuelPercentage: fuelPercentage,
      fuelLitres: fuelLitres,
      tankCapacity: tankCapacity,
      fuelRecordedAt: fuelRecordedAt,
      fuelIsStale: fuelIsStale,
      fuelStatus: fuelStatus,
      efficiency: efficiency,
      baselineEfficiency: baselineEfficiency,
      efficiencyDeviation: efficiencyDeviation,
      estimatedRangeKm: estimatedRangeKm,
      odometer: odometer,
      latitude: latitude ?? this.latitude,
      longitude: longitude ?? this.longitude,
      locationRecordedAt: locationRecordedAt ?? this.locationRecordedAt,
      hasOpenAlert: hasOpenAlert ?? this.hasOpenAlert,
    );
  }
}

/// A fuel or tracking alert from `/fleet/fraud-alerts`.
class FleetAlert {
  const FleetAlert({
    required this.id,
    required this.type,
    required this.severity,
    this.score,
    this.vehiclePlate,
    this.vehicleId,
    this.driverName,
    this.detectedAt,
    this.status,
  });

  final int id;
  final String type;
  final FipSeverity severity;
  final double? score;
  final String? vehiclePlate;
  final int? vehicleId;
  final String? driverName;
  final DateTime? detectedAt;
  final String? status;

  /// The alert type in the operator's words rather than the column value.
  String get title => switch (type) {
    'fuel_loss' => 'Possible fuel loss',
    'unexplained_drop' => 'Unexplained fuel drop',
    'low_fuel' => 'Low fuel',
    'excessive_consumption' => 'Excessive consumption',
    'duplicate_receipt' => 'Duplicate receipt',
    'price_mismatch' => 'Price mismatch',
    'volume_exceeds_capacity' => 'Volume exceeds tank capacity',
    'location_mismatch' => 'Location mismatch',
    'tracking_gap' => 'Tracking gap',
    _ => type.replaceAll('_', ' '),
  };

  String get detectedLabel =>
      detectedAt == null ? 'Unknown time' : Formatters.relative(detectedAt);

  factory FleetAlert.fromJson(Map<String, dynamic> json) {
    final vehicle = json['vehicle'] as Map<String, dynamic>?;
    final driver = json['driver'] as Map<String, dynamic>?;

    return FleetAlert(
      id: json['id'] as int,
      type: (json['alert_type'] ?? json['type'] ?? 'unknown') as String,
      severity: FipSeverityStyle.parse(json['severity'] as String?),
      score: json['score'] == null ? null : (json['score'] as num).toDouble(),
      vehicleId: vehicle?['id'] as int?,
      vehiclePlate: vehicle?['plate_number'] as String? ?? vehicle?['nickname'] as String?,
      driverName: driver == null
          ? null
          : [driver['first_name'], driver['last_name']].whereType<String>().join(' ').trim(),
      detectedAt: json['detected_at'] == null
          ? null
          : DateTime.tryParse(json['detected_at'] as String)?.toLocal(),
      status: json['status'] as String?,
    );
  }
}

/// The four Fleet Pulse figures, derived rather than fetched.
class FleetPulseData {
  const FleetPulseData({
    required this.total,
    required this.active,
    required this.moving,
    required this.stopped,
    required this.attention,
  });

  final int total;
  final int active;
  final int moving;
  final int stopped;
  final int attention;

  factory FleetPulseData.from(List<FleetVehicle> vehicles) {
    return FleetPulseData(
      total: vehicles.length,
      active: vehicles.where((v) => v.isReporting).length,
      moving: vehicles.where((v) => v.status == FleetStatus.moving).length,
      stopped: vehicles.where((v) => v.status == FleetStatus.stopped).length,
      attention: vehicles.where((v) => v.status == FleetStatus.attention).length,
    );
  }

  static const empty = FleetPulseData(total: 0, active: 0, moving: 0, stopped: 0, attention: 0);
}

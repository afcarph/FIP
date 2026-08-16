import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../shared/providers/app_providers.dart';

/// The driver's own trips.
///
/// `GET /fleet/trips` narrows itself to the signed-in driver's record, so this
/// asks for "my trips" without the client filtering anything or knowing which
/// Driver row it belongs to. Nothing here is derived: the status, the actions
/// and the odometer all come from the server, which owns the state machine.
class DriverTrip {
  const DriverTrip({
    required this.id,
    required this.reference,
    required this.status,
    required this.can,
    this.origin,
    this.destination,
    this.purpose,
    this.plateNumber,
    this.odometerStart,
    this.scheduledFor,
  });

  final int id;
  final String reference;
  final String status;

  /// The transitions the *trip* may make from here, as the server reported
  /// them — its state machine, not this caller's permissions. It lists
  /// `cancelled` for a dispatched trip even though a driver may not cancel
  /// one, so only the two a driver can perform are read below. Reading it at
  /// all is what stops the app inventing a copy of the lifecycle that could
  /// drift from the server's.
  final List<String> can;

  final String? origin;
  final String? destination;
  final String? purpose;
  final String? plateNumber;
  final int? odometerStart;
  final DateTime? scheduledFor;

  bool get canStart => can.contains('in_progress');
  bool get canComplete => can.contains('completed');

  /// Work the driver still has to do something about. A completed or cancelled
  /// trip is history and belongs on no one's home screen.
  bool get isLive => status == 'dispatched' || status == 'in_progress';

  String get statusLabel => switch (status) {
    'draft' => 'Not yet dispatched',
    'dispatched' => 'Ready to start',
    'in_progress' => 'On the road',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    _ => status.replaceAll('_', ' '),
  };

  String get route {
    final from = origin ?? '—';
    final to = destination ?? '—';

    return '$from → $to';
  }

  static DriverTrip fromJson(Map<String, dynamic> json) {
    final vehicle = json['vehicle'] as Map<String, dynamic>?;
    final odometer = json['odometer'] as Map<String, dynamic>?;
    final timeline = json['timeline'] as Map<String, dynamic>?;
    final scheduled = timeline?['scheduled_for'] as String?;

    return DriverTrip(
      id: json['id'] as int,
      reference: (json['reference_no'] as String?) ?? 'Trip ${json['id']}',
      status: (json['status'] as String?) ?? 'draft',
      can: ((json['can'] as List<dynamic>?) ?? const []).cast<String>(),
      origin: json['origin'] as String?,
      destination: json['destination'] as String?,
      purpose: json['purpose'] as String?,
      plateNumber: vehicle?['plate_number'] as String?,
      odometerStart: (odometer?['start'] as num?)?.toInt(),
      scheduledFor: scheduled == null ? null : DateTime.tryParse(scheduled),
    );
  }
}

/// Trips assigned to this driver that still need something doing.
///
/// A driver without a Driver record behind their account gets an empty list
/// from the API rather than their company's work, so no special case is needed
/// here for that.
final driverTripsProvider = FutureProvider<List<DriverTrip>>((ref) async {
  final auth = ref.watch(authProvider);
  if (!auth.isAuthenticated) return const [];

  try {
    final api = ref.read(apiClientProvider);
    final response = await api.get<dynamic>('/fleet/trips', query: {'per_page': 50});
    final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

    return (data as List<dynamic>)
        .cast<Map<String, dynamic>>()
        .map(DriverTrip.fromJson)
        .where((trip) => trip.isLive)
        .toList();
  } catch (_) {
    // A refusal or an outage leaves the rest of the home screen intact. A
    // driver with no trip permission simply sees no trip card.
    return const [];
  }
});

/// Start or close a trip.
///
/// Both are POSTs the server validates against its own state machine; a stale
/// screen that asks for something no longer allowed gets a refusal rather than
/// a silent success, and the caller refreshes either way.
class TripActions {
  const TripActions(this._ref);

  final Ref _ref;

  Future<void> start(int tripId, {int? odometer}) async {
    await _ref.read(apiClientProvider).post<dynamic>(
      '/fleet/trips/$tripId/start',
      body: {if (odometer != null) 'odometer_start': odometer},
    );

    _ref.invalidate(driverTripsProvider);
  }

  Future<void> complete(int tripId, {int? odometer}) async {
    await _ref.read(apiClientProvider).post<dynamic>(
      '/fleet/trips/$tripId/complete',
      body: {if (odometer != null) 'odometer_end': odometer},
    );

    _ref.invalidate(driverTripsProvider);
  }
}

final tripActionsProvider = Provider<TripActions>(TripActions.new);

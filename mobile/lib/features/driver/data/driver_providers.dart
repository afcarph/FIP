import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../shared/providers/app_providers.dart';
import '../../fleet/data/fleet_models.dart';

/// The driver's own view: one vehicle, one device, their own alerts.
///
/// Everything here is scoped by what the API already returns to a driver. No
/// endpoint is called that a driver is not authorised for, and nothing is
/// derived that the server has not measured — a driver dashboard showing
/// invented distance would be worse than one showing less.

/// The vehicle this driver is assigned to, or null when there is no
/// assignment.
///
/// The match is on `assigned_driver.user_id`: `vehicle_assignments` is the
/// source of truth, and the client only knows who is signed in. A driver with
/// no assignment gets null, never a guess at one of their company's vehicles.
final assignedVehicleProvider = FutureProvider<FleetVehicle?>((ref) async {
  final auth = ref.watch(authProvider);
  if (!auth.isAuthenticated) return null;

  final userId = auth.user?['id'] as int?;
  if (userId == null) return null;

  final api = ref.read(apiClientProvider);
  final response = await api.get<dynamic>('/vehicles', query: {'per_page': 100});
  final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

  for (final raw in (data as List<dynamic>).cast<Map<String, dynamic>>()) {
    final assigned = raw['assigned_driver'] as Map<String, dynamic>?;

    if (assigned == null || assigned['user_id'] != userId) continue;

    final vehicle = FleetVehicle.fromJson(raw);

    // /vehicles carries no position, and /fleet/locations is refused to a
    // driver — so without this the driver's own vehicle always read Offline
    // while the server held a fresh fix their own phone had just sent.
    try {
      final position = await api.get<dynamic>('/vehicles/${vehicle.id}/location');
      final located = position is Map<String, dynamic>
          ? position['data'] ?? position
          : position;

      if (located is Map<String, dynamic>) return vehicle.withLocation(located);
    } catch (_) {
      // A refusal or an outage must not blank the vehicle card; it simply has
      // no position, which the card already knows how to say.
    }

    return vehicle;
  }

  return null;
});

/// Open alerts for the driver's own vehicle only.
final driverAlertsProvider = FutureProvider<List<FleetAlert>>((ref) async {
  final vehicle = await ref.watch(assignedVehicleProvider.future);
  if (vehicle == null) return const [];

  try {
    final api = ref.read(apiClientProvider);
    final response = await api.get<dynamic>(
      '/fleet/fraud-alerts',
      query: {'status': 'open', 'per_page': 50},
    );
    final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

    return (data as List<dynamic>)
        .cast<Map<String, dynamic>>()
        .map(FleetAlert.fromJson)
        // The endpoint is already tenant-scoped; this narrows it further to
        // the one vehicle the driver is responsible for.
        .where((alert) => alert.vehicleId == vehicle.id)
        .toList();
  } catch (_) {
    return const [];
  }
});

/// Whether this installation is registered, and to which vehicle.
class DeviceRegistration {
  const DeviceRegistration({required this.isRegistered, this.id, this.vehicleId, this.isRevoked = false});

  final bool isRegistered;
  final int? id;
  final int? vehicleId;
  final bool isRevoked;

  bool get isAssigned => vehicleId != null;

  static const none = DeviceRegistration(isRegistered: false);
}

/// Looks up this installation in the driver's own device list.
///
/// `GET /devices` is already scoped to the caller, so this asks "is my current
/// X-Device-Id among my devices" without needing any elevated permission.
final deviceRegistrationProvider = FutureProvider<DeviceRegistration>((ref) async {
  final auth = ref.watch(authProvider);
  if (!auth.isAuthenticated) return DeviceRegistration.none;

  final api = ref.read(apiClientProvider);
  final uuid = await api.deviceUuid();

  try {
    final response = await api.get<dynamic>('/devices');
    final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

    for (final raw in (data as List<dynamic>).cast<Map<String, dynamic>>()) {
      if (raw['device_uuid'] != uuid) continue;

      return DeviceRegistration(
        isRegistered: true,
        id: raw['id'] as int?,
        vehicleId: (raw['vehicle'] as Map<String, dynamic>?)?['id'] as int?,
        isRevoked: raw['is_revoked'] == true,
      );
    }
  } catch (_) {
    return DeviceRegistration.none;
  }

  return DeviceRegistration.none;
});

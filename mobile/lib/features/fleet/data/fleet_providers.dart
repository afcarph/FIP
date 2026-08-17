import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../shared/providers/app_providers.dart';
import 'fleet_models.dart';

/// Fleet data, assembled from the endpoints that already exist.
///
/// The fleet view needs three things the API returns separately — the
/// vehicles, their last known positions, and the open alerts — so they are
/// fetched independently and joined here. Doing the join on the client keeps
/// this redesign to the existing API contract.
///
/// Every fetch below watches [authProvider] rather than only reading the
/// client. The router builds /home while the stored session is still being
/// restored -- it deliberately holds the redirect so a signed-in user is not
/// bounced to login -- so without this a provider fires before the token
/// exists, caches the 401, and never retries once sign-in completes. Watching
/// the auth state means a session change rebuilds the request instead.

final _vehiclesRawProvider = FutureProvider<List<Map<String, dynamic>>>((ref) async {
  if (!ref.watch(authProvider).isAuthenticated) return const [];

  final api = ref.read(apiClientProvider);
  final response = await api.get<dynamic>('/vehicles', query: {'per_page': 100});

  final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

  return (data as List<dynamic>).cast<Map<String, dynamic>>();
});

/// Last known position per vehicle. Requires `devices.location.view`, so a
/// user without it still gets the fleet list — just without live status.
final _locationsRawProvider = FutureProvider<List<Map<String, dynamic>>>((ref) async {
  if (!ref.watch(authProvider).isAuthenticated) return const [];

  try {
    final api = ref.read(apiClientProvider);
    final response = await api.get<dynamic>('/fleet/locations');
    final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

    return (data as List<dynamic>).cast<Map<String, dynamic>>();
  } catch (_) {
    // A permission failure here must not blank the whole screen. Vehicles
    // simply show as offline, which is what the operator can actually see.
    return const [];
  }
});

final fleetAlertsProvider = FutureProvider<List<FleetAlert>>((ref) async {
  if (!ref.watch(authProvider).isAuthenticated) return const [];

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
        .toList();
  } catch (_) {
    return const [];
  }
});

/// The joined fleet: vehicles carrying their live position and alert flag.
final fleetVehiclesProvider = FutureProvider<List<FleetVehicle>>((ref) async {
  final vehicles = await ref.watch(_vehiclesRawProvider.future);
  final locations = await ref.watch(_locationsRawProvider.future);
  final alerts = await ref.watch(fleetAlertsProvider.future);

  final byVehicle = {
    for (final location in locations)
      if (location['vehicle_id'] != null) location['vehicle_id'] as int: location,
  };
  final flagged = alerts.map((a) => a.vehicleId).whereType<int>().toSet();

  return vehicles.map((json) {
    var vehicle = FleetVehicle.fromJson(json);
    final location = byVehicle[vehicle.id];

    if (location != null) vehicle = vehicle.withLocation(location);
    if (flagged.contains(vehicle.id)) vehicle = vehicle.copyWith(hasOpenAlert: true);

    return vehicle;
  }).toList();
});

/// The four Fleet Pulse figures.
final fleetPulseProvider = Provider<AsyncValue<FleetPulseData>>((ref) {
  return ref.watch(fleetVehiclesProvider).whenData(FleetPulseData.from);
});

/// A single vehicle, for the detail screen.
final fleetVehicleProvider = Provider.family<AsyncValue<FleetVehicle?>, int>((ref, id) {
  return ref.watch(fleetVehiclesProvider).whenData(
    (vehicles) => vehicles.where((v) => v.id == id).firstOrNull,
  );
});

/// Location history for one vehicle. Gated server-side on
/// `devices.location.history`, which most roles do not hold.
final vehicleLocationHistoryProvider =
    FutureProvider.family<List<Map<String, dynamic>>, int>((ref, id) async {
      if (!ref.watch(authProvider).isAuthenticated) return const [];

      try {
        final api = ref.read(apiClientProvider);
        final response = await api.get<dynamic>(
          '/fleet/vehicles/$id/locations',
          query: {'per_page': 50},
        );
        final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

        return (data as List<dynamic>).cast<Map<String, dynamic>>();
      } catch (_) {
        return const [];
      }
    });

/// Fuel reading history for one vehicle.
final vehicleFuelHistoryProvider =
    FutureProvider.family<List<Map<String, dynamic>>, int>((ref, id) async {
      if (!ref.watch(authProvider).isAuthenticated) return const [];

      try {
        final api = ref.read(apiClientProvider);
        final response = await api.get<dynamic>(
          '/vehicles/$id/fuel-readings',
          query: {'per_page': 30},
        );
        final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

        return (data as List<dynamic>).cast<Map<String, dynamic>>();
      } catch (_) {
        return const [];
      }
    });

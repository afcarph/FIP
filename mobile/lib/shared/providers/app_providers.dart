import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../../core/config/app_config.dart';
import '../../core/network/api_client.dart';

/// Single client instance for the whole app — it owns the token and the
/// shared refresh lock, so a second instance would defeat both.
/// The API host. Overridden in `main()` when a UAT tester has repointed the
/// app, and otherwise the compile-time default.
final apiBaseUrlProvider = Provider<String>((ref) => AppConfig.apiBaseUrl);

// Reads rather than watches: the value is fixed for the process, and watching
// would rebuild the client — and with it the auth notifier — on any refresh.
final apiClientProvider = Provider<ApiClient>(
  (ref) => ApiClient(baseUrl: ref.read(apiBaseUrlProvider)),
);

// ------------------------------------------------------------------ auth ---

class AuthState {
  const AuthState({
    this.user,
    this.roles = const [],
    this.permissions = const [],
    this.isLoading = true,
  });

  final Map<String, dynamic>? user;
  final List<String> roles;
  final List<String> permissions;
  final bool isLoading;

  bool get isAuthenticated => user != null;

  bool get isAdmin => roles.contains('super_admin') || roles.contains('system_admin');

  bool can(String permission) => roles.contains('super_admin') || permissions.contains(permission);

  bool hasRole(List<String> candidates) => candidates.any(roles.contains);

  AuthState copyWith({
    Map<String, dynamic>? user,
    List<String>? roles,
    List<String>? permissions,
    bool? isLoading,
  }) {
    return AuthState(
      user: user ?? this.user,
      roles: roles ?? this.roles,
      permissions: permissions ?? this.permissions,
      isLoading: isLoading ?? this.isLoading,
    );
  }
}

class AuthNotifier extends StateNotifier<AuthState> {
  AuthNotifier(this._api) : super(const AuthState()) {
    unawaited(restore());
  }

  final ApiClient _api;

  /// Re-establish the session from the stored token at cold start.
  Future<void> restore() async {
    final token = await _api.readToken();

    if (token == null) {
      state = const AuthState(isLoading: false);
      return;
    }

    try {
      final data = await _api.get<Map<String, dynamic>>('/auth/me');

      state = AuthState(
        user: data['user'] as Map<String, dynamic>?,
        roles: (data['roles'] as List<dynamic>? ?? []).cast<String>(),
        permissions: (data['permissions'] as List<dynamic>? ?? []).cast<String>(),
        isLoading: false,
      );
    } catch (_) {
      // An unusable token is worse than none — clear it and start clean.
      await _api.clearSession();
      state = const AuthState(isLoading: false);
    }
  }

  Future<void> completeSignIn(Map<String, dynamic> session) async {
    await _api.saveToken(session['access_token'] as String);

    state = AuthState(
      user: session['user'] as Map<String, dynamic>?,
      roles: (session['roles'] as List<dynamic>? ?? []).cast<String>(),
      permissions: (session['permissions'] as List<dynamic>? ?? []).cast<String>(),
      isLoading: false,
    );
  }

  Future<void> signOut() async {
    try {
      await _api.post<dynamic>('/auth/logout', body: {'device_uuid': await _api.deviceUuid()});
    } catch (_) {
      // A failed call must still end the local session.
    }

    await _api.clearSession();
    state = const AuthState(isLoading: false);
  }
}

final authProvider = StateNotifierProvider<AuthNotifier, AuthState>(
  (ref) => AuthNotifier(ref.watch(apiClientProvider)),
);

// -------------------------------------------------------------- location ---

class UserLocation {
  const UserLocation({required this.latitude, required this.longitude, required this.isFallback});

  final double latitude;
  final double longitude;

  /// True when the user declined or the fix failed, so the UI can say
  /// "showing prices near Makati" instead of implying it knows where they are.
  final bool isFallback;

  static const fallback = UserLocation(
    latitude: AppConfig.fallbackLatitude,
    longitude: AppConfig.fallbackLongitude,
    isFallback: true,
  );
}

/// Current position, defaulting to Metro Manila rather than failing.
final locationProvider = FutureProvider<UserLocation>((ref) async {
  // The whole body is guarded, not just getCurrentPosition. Every branch below
  // already degrades to a fallback position rather than failing, but the
  // service and permission checks are themselves platform calls that can throw
  // — and when they did, the exception escaped and took out the map, which
  // showed "Something went wrong" and never requested a single station.
  // Falling back to a default position is the entire point of this provider,
  // so it should not have a path that throws instead.
  try {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return UserLocation.fallback;
    }

    var permission = await Geolocator.checkPermission();

    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }

    if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
      return UserLocation.fallback;
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 10),
      ),
    );

    return UserLocation(
      latitude: position.latitude,
      longitude: position.longitude,
      isFallback: false,
    );
  } catch (error, stackTrace) {
    // A timeout on a weak GPS fix is normal, and a platform channel failure is
    // survivable; both degrade to the fallback. Recorded rather than swallowed
    // so a systematic failure is still diagnosable.
    debugPrint('locationProvider fell back: $error');
    FlutterError.reportError(
      FlutterErrorDetails(exception: error, stack: stackTrace, library: 'locationProvider'),
    );

    return UserLocation.fallback;
  }
});

// ------------------------------------------------------------------ data ---

final fuelTypesProvider = FutureProvider<List<Map<String, dynamic>>>((ref) async {
  final data = await ref
      .watch(apiClientProvider)
      .get<List<dynamic>>('/prices/fuel-types', skipAuth: true);

  return data.cast<Map<String, dynamic>>();
});

final forecastsProvider = FutureProvider<List<Map<String, dynamic>>>((ref) async {
  final data = await ref.watch(apiClientProvider).get<List<dynamic>>('/forecasts', skipAuth: true);

  return data.cast<Map<String, dynamic>>();
});

final dashboardProvider = FutureProvider<Map<String, dynamic>>((ref) async {
  // Watching the session is what makes this correct, not just fresh. The
  // dashboard is the landing route, so this provider is first built while the
  // login screen is still on top: that attempt has no token, returns 401, and
  // the cached error is what the user is shown the moment they sign in.
  // Depending on isAuthenticated re-runs the fetch when the session arrives.
  ref.watch(authProvider.select((auth) => auth.isAuthenticated));

  return ref.watch(apiClientProvider).get<Map<String, dynamic>>('/dashboard');
});

/// Nearby stations for the current position and filters.
final nearbyStationsProvider =
    FutureProvider.family<List<Map<String, dynamic>>, ({double radiusKm, int? fuelTypeId})>((
      ref,
      args,
    ) async {
      final location = await ref.watch(locationProvider.future);

      final data = await ref
          .watch(apiClientProvider)
          .get<List<dynamic>>(
            '/stations/nearby',
            query: {
              'latitude': location.latitude,
              'longitude': location.longitude,
              'radius_km': args.radiusKm,
              'fuel_type_id': args.fuelTypeId,
            },
            skipAuth: true,
          );

      return data.cast<Map<String, dynamic>>();
    });

final vehiclesProvider = FutureProvider<List<Map<String, dynamic>>>((ref) async {
  final data = await ref.watch(apiClientProvider).get<List<dynamic>>('/vehicles');

  return data.cast<Map<String, dynamic>>();
});

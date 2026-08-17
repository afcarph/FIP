import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../../../shared/providers/app_providers.dart';
import '../data/tracking_service.dart';

/// Tracking state, and the lifecycle that makes "foreground only" true.
///
/// The service samples while it is started; nothing else stops it. Binding
/// start and stop to the app lifecycle here is what turns the privacy claim
/// into a mechanism — when the app is backgrounded the timer is cancelled, and
/// there is no background isolate to take over.

final trackingServiceProvider = Provider<TrackingService>((ref) {
  final service = TrackingService(api: ref.read(apiClientProvider));
  ref.onDispose(service.dispose);

  return service;
});

class TrackingController extends StateNotifier<TrackingStatus> {
  TrackingController(this._service) : super(_service.currentStatus) {
    _subscription = _service.status.listen((status) => state = status);

    _lifecycle = AppLifecycleListener(
      onResume: resume,
      // inactive covers the iOS app switcher and an incoming call, where the
      // app is visible but not in use. Sampling through either would be
      // collection the driver did not initiate.
      onInactive: pause,
      onHide: pause,
      onPause: pause,
    );
  }

  final TrackingService _service;
  late final AppLifecycleListener _lifecycle;
  StreamSubscription<TrackingStatus>? _subscription;

  bool _enabled = false;

  /// Whether the driver has opted into sharing for this session.
  bool get isEnabled => _enabled;

  Future<TrackingStatus> enable() async {
    _enabled = true;

    return _service.start();
  }

  void disable() {
    _enabled = false;
    _service.stop();
  }

  Future<void> resume() async {
    if (_enabled) await _service.start();
  }

  void pause() {
    // Deliberately does not clear _enabled: returning to the app should resume
    // what the driver switched on, without asking again.
    if (_enabled) _service.stop();
  }

  /// Send the driver to the OS settings page for this app.
  Future<void> openSettings(TrackingStatus status) async {
    if (status == TrackingStatus.locationServicesOff) {
      await Geolocator.openLocationSettings();

      return;
    }

    await Geolocator.openAppSettings();
  }

  @override
  void dispose() {
    _subscription?.cancel();
    _lifecycle.dispose();
    super.dispose();
  }
}

final trackingControllerProvider = StateNotifierProvider<TrackingController, TrackingStatus>(
  (ref) => TrackingController(ref.read(trackingServiceProvider)),
);

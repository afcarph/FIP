import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import 'location_queue.dart';

/// Why tracking is not running, when it is not.
///
/// Modelled explicitly rather than as a nullable error string, because the app
/// has to say something different for each: a permission the driver can grant,
/// a service they can switch on, and a registration only an administrator can
/// restore are three different conversations.
enum TrackingStatus {
  idle,
  running,
  permissionDenied,
  permissionDeniedForever,
  locationServicesOff,
  deviceNotRegistered,
  deviceRevoked,
}

/// Foreground location reporting for a registered driver device.
///
/// Sampling runs only while this service is started, and the app starts it only
/// while it is in the foreground. There is no background isolate, no
/// `UIBackgroundModes`, and no `ACCESS_BACKGROUND_LOCATION`: the privacy
/// document promises collection stops when the app does, and the absence of
/// those three things is what makes that true rather than aspirational.
///
/// Positions go to a persistent queue first and are uploaded from there, so a
/// tunnel costs latency rather than data.
class TrackingService {
  TrackingService({required ApiClient api, LocationQueue? queue})
    : _api = api,
      _queue = queue ?? LocationQueue();

  final ApiClient _api;
  final LocationQueue _queue;

  Timer? _timer;
  Position? _lastSampled;
  bool _flushing = false;

  final _statusController = StreamController<TrackingStatus>.broadcast();

  Stream<TrackingStatus> get status => _statusController.stream;

  TrackingStatus _status = TrackingStatus.idle;

  TrackingStatus get currentStatus => _status;

  bool get isRunning => _timer?.isActive ?? false;

  /// Register this installation with the server.
  ///
  /// The identifier is the one ApiClient already persists and sends as
  /// `X-Device-Id`; generating a second one here would give the app two
  /// identities and the server no way to match them.
  Future<Map<String, dynamic>> register({
    required String platform,
    String? deviceName,
    String? appVersion,
    String? osVersion,
  }) async {
    return _api.post<Map<String, dynamic>>(
      '/devices',
      body: {
        'device_uuid': await _api.deviceUuid(),
        'platform': platform,
        if (deviceName != null) 'device_name': deviceName,
        if (appVersion != null) 'app_version': appVersion,
        if (osVersion != null) 'os_version': osVersion,
      },
    );
  }

  /// Begin sampling, if the driver and the platform both allow it.
  ///
  /// Returns the resulting status rather than throwing: every failure here is
  /// a state the UI has to render, not an exception to swallow.
  Future<TrackingStatus> start({
    Duration interval = const Duration(seconds: 120),
    double minimumDistanceMetres = 50,
  }) async {
    final permitted = await _ensurePermission();

    if (permitted != TrackingStatus.running) {
      return _emit(permitted);
    }

    _timer?.cancel();
    // Sample immediately so the first position does not wait a full interval —
    // a driver who has just opened the app should appear on the map now.
    unawaited(_sample(minimumDistanceMetres));
    _timer = Timer.periodic(interval, (_) => unawaited(_sample(minimumDistanceMetres)));

    return _emit(TrackingStatus.running);
  }

  /// Stop sampling. Called when the app leaves the foreground, and that is what
  /// makes "only while in use" a mechanism rather than a claim.
  void stop() {
    _timer?.cancel();
    _timer = null;

    if (_status == TrackingStatus.running) _emit(TrackingStatus.idle);
  }

  Future<void> dispose() async {
    stop();
    await _statusController.close();
  }

  // -------------------------------------------------------------- internals ---

  Future<TrackingStatus> _ensurePermission() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        return TrackingStatus.locationServicesOff;
      }

      var permission = await Geolocator.checkPermission();

      if (permission == LocationPermission.denied) {
        // Asked once, here, after the app has explained why. Re-prompting on
        // every start is how an app trains someone to deny it permanently.
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.deniedForever) {
        return TrackingStatus.permissionDeniedForever;
      }

      if (permission == LocationPermission.denied) {
        return TrackingStatus.permissionDenied;
      }

      return TrackingStatus.running;
    } catch (error, stackTrace) {
      // A platform channel failure must not take the screen down with it.
      FlutterError.reportError(
        FlutterErrorDetails(exception: error, stack: stackTrace, library: 'TrackingService'),
      );

      return TrackingStatus.locationServicesOff;
    }
  }

  Future<void> _sample(double minimumDistanceMetres) async {
    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 20),
        ),
      );

      // A parked vehicle should cost one row, not one per interval.
      final previous = _lastSampled;

      if (previous != null) {
        final moved = Geolocator.distanceBetween(
          previous.latitude,
          previous.longitude,
          position.latitude,
          position.longitude,
        );

        if (moved < minimumDistanceMetres) return;
      }

      _lastSampled = position;

      await _queue.add({
        'latitude': position.latitude,
        'longitude': position.longitude,
        'accuracy_m': position.accuracy,
        'altitude_m': position.altitude,
        'speed_kph': position.speed * 3.6, // m/s from the platform
        'heading_deg': position.heading >= 0 ? position.heading : null,
        'recorded_at': position.timestamp.toUtc().toIso8601String(),
      });

      await flush();
    } on TimeoutException {
      // A weak fix is ordinary. Nothing is queued, and no position is invented.
    } catch (error, stackTrace) {
      FlutterError.reportError(
        FlutterErrorDetails(
          exception: error,
          stack: stackTrace,
          library: 'TrackingService._sample',
        ),
      );
    }
  }

  /// Upload whatever is queued.
  ///
  /// Guarded against overlap: sampling and flushing are both timer-driven, and
  /// two concurrent flushes would send the same points twice. The server would
  /// deduplicate them, but spending a driver's data twice to prove that is not
  /// a good trade.
  Future<void> flush() async {
    if (_flushing) return;

    final queued = await _queue.read();

    if (queued.isEmpty) return;

    _flushing = true;

    try {
      await _api.post<Map<String, dynamic>>('/devices/location', body: {'points': queued});

      // Remove exactly what was sent. The queue may have grown during the
      // request, and trimming by count would discard unsent positions.
      await _queue.removeRecorded(queued.map((point) => point['recorded_at'] as String));
    } on ApiException catch (error) {
      _handleUploadFailure(error);
    } catch (_) {
      // Offline. The queue is the retry: leave it alone and try next interval.
    } finally {
      _flushing = false;
    }
  }

  void _handleUploadFailure(ApiException error) {
    switch (error.code) {
      case 'device_revoked':
        // Revocation is final and server-side. Stop sampling and drop what is
        // queued: continuing to collect positions the server will refuse would
        // be gathering data for nobody.
        stop();
        unawaited(_queue.clear());
        _emit(TrackingStatus.deviceRevoked);

      case 'device_not_registered':
      case 'device_unassigned':
        stop();
        _emit(TrackingStatus.deviceNotRegistered);

      default:
        // A validation failure would repeat forever on the same payload, so the
        // queue is cleared rather than retried; anything else is transient and
        // the queue survives for the next attempt.
        if (error.statusCode == 422) unawaited(_queue.clear());
    }
  }

  TrackingStatus _emit(TrackingStatus status) {
    _status = status;

    if (!_statusController.isClosed) _statusController.add(status);

    return status;
  }
}

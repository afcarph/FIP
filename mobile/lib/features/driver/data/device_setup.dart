import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../shared/providers/app_providers.dart';
import '../../tracking/presentation/tracking_providers.dart';
import 'build_identity.dart';
import 'driver_providers.dart';

/// Where a driver is in setting this phone up.
enum DeviceSetupStep { checking, noAssignment, needsSetup, ready, revoked, failed }

class DeviceSetupState {
  const DeviceSetupState(this.step, {this.message, this.busy = false});

  final DeviceSetupStep step;
  final String? message;
  final bool busy;
}

/// Registers this installation and attaches it to the driver's assigned
/// vehicle, using the endpoints that already exist.
///
/// This is not a second registration mechanism: it calls `POST /devices` and
/// `PATCH /devices/{id}` exactly as an administrator would, and every
/// authorisation decision stays on the server. The driver can do this because
/// the device is theirs and the vehicle is the one they are assigned — not
/// because the app grants them anything.
///
/// The vehicle is never chosen by the driver. It comes from
/// `vehicle_assignments` via [assignedVehicleProvider]; with no assignment the
/// flow stops at [DeviceSetupStep.noAssignment] rather than picking one.
class DeviceSetupController extends StateNotifier<DeviceSetupState> {
  DeviceSetupController(this._ref) : super(const DeviceSetupState(DeviceSetupStep.checking)) {
    refresh();
  }

  final Ref _ref;

  Future<void> refresh() async {
    state = const DeviceSetupState(DeviceSetupStep.checking);

    final registration = await _ref.read(deviceRegistrationProvider.future);
    final vehicle = await _ref.read(assignedVehicleProvider.future);

    if (registration.isRevoked) {
      state = const DeviceSetupState(DeviceSetupStep.revoked);

      return;
    }

    if (vehicle == null) {
      state = const DeviceSetupState(DeviceSetupStep.noAssignment);

      return;
    }

    final ready = registration.isRegistered && registration.vehicleId == vehicle.id;

    // A device set up before the app sent its version never runs setup again,
    // so the build behind its telemetry stays unknown. Reported here instead,
    // via PATCH — re-registering would reset fcm_token.
    if (ready && registration.id != null) {
      unawaited(_reportBuildIdentity(registration.id!));
    }

    state = ready
        ? const DeviceSetupState(DeviceSetupStep.ready)
        : const DeviceSetupState(DeviceSetupStep.needsSetup);
  }

  Future<void> _reportBuildIdentity(int deviceId) async {
    try {
      final build = await BuildIdentity.resolve();
      if (build.appVersion == null && build.osVersion == null) return;

      await _ref.read(apiClientProvider).patch<dynamic>('/devices/$deviceId', body: {
        if (build.appVersion != null) 'app_version': build.appVersion,
        if (build.osVersion != null) 'os_version': build.osVersion,
      });
    } catch (_) {
      // Attribution is useful, not essential. It must never break setup.
    }
  }

  /// Register, associate, then start reporting.
  ///
  /// Tracking is only enabled at the end, and enabling it is what prompts for
  /// location permission — so a driver who never runs setup is never asked.
  Future<void> completeSetup() async {
    final vehicle = await _ref.read(assignedVehicleProvider.future);

    if (vehicle == null) {
      state = const DeviceSetupState(DeviceSetupStep.noAssignment);

      return;
    }

    state = const DeviceSetupState(DeviceSetupStep.needsSetup, busy: true);

    try {
      final api = _ref.read(apiClientProvider);
      final tracking = _ref.read(trackingServiceProvider);

      // Resolved before registering so the record says which build produced
      // whatever the device later reports. The first pilot recorded eight
      // genuine positions against a device with both fields null, and the
      // measurements could not be attributed to a version.
      final build = await BuildIdentity.resolve();

      // Idempotent server-side on (user, device_uuid), so re-running setup
      // after a failed association does not create a second device — and a
      // device registered before this fix picks up its version on the next run.
      final registered = await tracking.register(
        platform: Platform.isIOS ? 'ios' : 'android',
        // What the handset is, not what the app is. Every device in the fleet
        // registered as "FIP mobile", so the owner's list could not tell two
        // phones apart on the one screen where a session gets revoked.
        deviceName: build.deviceName,
        appVersion: build.appVersion,
        osVersion: build.osVersion,
      );

      final id = (registered['data'] as Map<String, dynamic>?)?['id'] ?? registered['id'];

      if (id == null) {
        state = const DeviceSetupState(
          DeviceSetupStep.failed,
          message: 'The device was registered but the server returned no identifier.',
        );

        return;
      }

      await api.patch<dynamic>('/devices/$id', body: {'vehicle_id': vehicle.id});

      _ref.invalidate(deviceRegistrationProvider);
      await _ref.read(trackingControllerProvider.notifier).enable();

      state = const DeviceSetupState(DeviceSetupStep.ready);
    } catch (error) {
      // The server's own message is shown rather than a generic failure: a
      // driver told "not authorised for this vehicle" can act on it, and a
      // driver told "something went wrong" cannot.
      state = DeviceSetupState(DeviceSetupStep.failed, message: '$error');
    }
  }
}

final deviceSetupProvider =
    StateNotifierProvider<DeviceSetupController, DeviceSetupState>(
      (ref) => DeviceSetupController(ref),
    );

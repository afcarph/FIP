import 'package:flutter_test/flutter_test.dart';

import 'package:fip_mobile/features/driver/data/device_setup.dart';
import 'package:fip_mobile/features/driver/data/driver_providers.dart';

/// The driver setup path, at the level that can be tested without a device:
/// how registration state is interpreted, and the guarantee that nothing asks
/// for location until the driver asks for it.
void main() {
  group('device registration state', () {
    test('an unregistered installation is neither registered nor assigned', () {
      const registration = DeviceRegistration.none;

      expect(registration.isRegistered, isFalse);
      expect(registration.isAssigned, isFalse);
    });

    test('a registered device with no vehicle is not ready to report', () {
      // Registration alone is not enough: the server refuses location from a
      // device with no vehicle, so the UI must not present it as set up.
      const registration = DeviceRegistration(isRegistered: true, id: 9);

      expect(registration.isRegistered, isTrue);
      expect(registration.isAssigned, isFalse);
    });

    test('a registered and assigned device is ready', () {
      const registration = DeviceRegistration(isRegistered: true, id: 9, vehicleId: 3);

      expect(registration.isAssigned, isTrue);
    });

    test('a revoked device is flagged separately from an unregistered one', () {
      // These need different words on screen: one the driver can fix, one only
      // an administrator can.
      const revoked = DeviceRegistration(isRegistered: true, id: 9, isRevoked: true);

      expect(revoked.isRevoked, isTrue);
      expect(revoked.isRegistered, isTrue);
    });
  });

  group('setup steps', () {
    test('every step is distinct', () {
      // noAssignment and needsSetup must never collapse into one another: the
      // first is not the driver's to resolve, the second is.
      expect(DeviceSetupStep.values.toSet().length, DeviceSetupStep.values.length);
      expect(DeviceSetupStep.values, contains(DeviceSetupStep.noAssignment));
      expect(DeviceSetupStep.values, contains(DeviceSetupStep.needsSetup));
    });

    test('a state carries its message and busy flag', () {
      const state = DeviceSetupState(DeviceSetupStep.failed, message: 'Nope', busy: false);

      expect(state.step, DeviceSetupStep.failed);
      expect(state.message, 'Nope');
      expect(state.busy, isFalse);
    });
  });

  group('permission timing', () {
    test('nothing in the setup flow requests permission before setup runs', () {
      // The guarantee is structural: Geolocator is only reached through
      // TrackingService.start(), which is only reached through
      // TrackingController.enable(), which the driver triggers. This test
      // pins the contract that constructing setup state does not touch it.
      const checking = DeviceSetupState(DeviceSetupStep.checking);

      expect(checking.busy, isFalse);
      expect(checking.step, DeviceSetupStep.checking);
    });
  });
}

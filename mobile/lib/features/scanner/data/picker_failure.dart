import 'package:flutter/services.dart';

/// What to tell someone when the camera or photo library will not open.
///
/// `image_picker` surfaces platform errors as `PlatformException`, whose
/// `toString()` is developer text — a driver was being shown
/// `PlatformException(camera_access_denied, The user did not allow camera
/// access., null, null)`. That says nothing they can act on and looks like a
/// crash report.
///
/// Denial is not an error in the usual sense: the person made a choice, and
/// the message should tell them what that choice costs and how to undo it.
class PickerFailure {
  const PickerFailure._();

  /// A sentence for the driver, chosen from the platform's error code.
  static String describe(Object error) {
    if (error is! PlatformException) {
      return 'The photo could not be opened. Please try again.';
    }

    return switch (error.code) {
      'camera_access_denied' =>
        'FIP does not have camera access. You can turn it on in Settings, or choose an existing photo instead.',
      'photo_access_denied' =>
        'FIP does not have access to your photos. You can turn it on in Settings, or take a new photo instead.',
      // The simulator has no camera, and neither does a device with the
      // hardware disabled by policy.
      'camera_error' || 'no_available_camera' =>
        'No camera is available on this device. Choose an existing photo instead.',
      'multiple_request' => 'A photo is already being selected. Finish that first.',
      'invalid_image' => 'That file is not an image FIP can read. Try another photo.',
      _ => 'The photo could not be opened. Please try again.',
    };
  }

  /// Whether the failure is a permission the driver can grant in Settings.
  ///
  /// Drives whether the UI offers a way there: a button that opens Settings is
  /// helpful for a denial and pointless for a missing camera.
  static bool isPermissionDenial(Object error) =>
      error is PlatformException &&
      (error.code == 'camera_access_denied' || error.code == 'photo_access_denied');
}

import 'dart:io' show Platform;

import 'package:device_info_plus/device_info_plus.dart';
import 'package:package_info_plus/package_info_plus.dart';

/// Which build produced a measurement.
///
/// The first pilot recorded eight genuine positions against a device whose
/// `app_version` and `os_version` were both null, so the data could not be
/// attributed to a build. Every figure a pilot produces — battery, sampling
/// cadence, upload success — is only comparable across runs if the version
/// behind it is known.
///
/// Both values are best-effort: a platform channel that fails must not stop a
/// driver registering their device, so a failure yields null and registration
/// proceeds without it. Null is honest; a placeholder version would be worse
/// than none, because it would look like a real attribution.
class BuildIdentity {
  const BuildIdentity({this.appVersion, this.osVersion, this.deviceName});

  /// `1.0.0+1` — the marketing version and build number, matching what the
  /// stores show, so a tester quoting "1.0.0 (1)" and a database row agree.
  final String? appVersion;

  /// The platform's own description, e.g. `iOS Version 26.5.2 (Build 23F84)`.
  final String? osVersion;

  /// What the handset is, for the owner's device list — `Google Pixel 8`,
  /// `iPhone`. Registrations used to arrive named `FIP mobile`, which is the
  /// same for every phone in the fleet, or with no name at all; neither helps
  /// somebody deciding which session to revoke.
  ///
  /// The model only. Since iOS 16 the user-assigned name is not available
  /// without an entitlement, and that suits this: a device list wants to say
  /// what the thing is, not what its owner calls it.
  final String? deviceName;

  static Future<BuildIdentity> resolve() async {
    String? app;

    try {
      final info = await PackageInfo.fromPlatform();
      final version = info.version.trim();
      final build = info.buildNumber.trim();

      if (version.isNotEmpty) app = build.isEmpty ? version : '$version+$build';
    } catch (_) {
      // Left null: see the class comment.
    }

    String? os;

    try {
      // dart:io rather than a plugin — one fewer dependency for a string the
      // platform already exposes. Not prefixed with the OS name: `platform`
      // is already sent as its own field, and the column is short.
      os = Platform.operatingSystemVersion.trim();
    } catch (_) {
      os = null;
    }

    String? name;

    try {
      final info = DeviceInfoPlugin();

      if (Platform.isAndroid) {
        final android = await info.androidInfo;
        name = describeAndroid(android.manufacturer, android.model);
      } else if (Platform.isIOS) {
        final ios = await info.iosInfo;
        name = ios.model.trim().isEmpty ? null : ios.model.trim();
      }
    } catch (_) {
      // Same rule as the versions above: a failed platform channel must not
      // stop a driver registering. Null is honest.
      name = null;
    }

    return BuildIdentity(
      appVersion: _truncate(app, 24),
      osVersion: _truncate(os, 32),
      deviceName: _truncate(name, 120),
    );
  }

  /// `Google` + `Pixel 8` reads as one name; `samsung` + `SM-S911B` needs the
  /// maker to mean anything. Kept pure so the shapes can be tested without a
  /// device — the plugin returns nothing under a unit test.
  static String? describeAndroid(String? manufacturer, String? model) {
    final maker = (manufacturer ?? '').trim();
    final device = (model ?? '').trim();

    if (device.isEmpty) return maker.isEmpty ? null : _capitalise(maker);
    if (maker.isEmpty) return device;

    // Manufacturers vary on whether the model already carries the brand.
    if (device.toLowerCase().startsWith(maker.toLowerCase())) return device;

    return '${_capitalise(maker)} $device';
  }

  static String _capitalise(String value) =>
      value.isEmpty ? value : value[0].toUpperCase() + value.substring(1);

  /// The columns are VARCHAR(24) and VARCHAR(32), and the request validates
  /// `max:24` / `max:32`. An over-long string would fail validation and take
  /// the whole registration with it, so it is trimmed to fit rather than
  /// allowed to turn a version string into a failed device setup.
  static String? _truncate(String? value, int limit) {
    if (value == null || value.isEmpty) return null;

    return value.length <= limit ? value : value.substring(0, limit);
  }
}

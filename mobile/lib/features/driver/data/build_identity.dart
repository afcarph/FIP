import 'dart:io' show Platform;

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
  const BuildIdentity({this.appVersion, this.osVersion});

  /// `1.0.0+1` — the marketing version and build number, matching what the
  /// stores show, so a tester quoting "1.0.0 (1)" and a database row agree.
  final String? appVersion;

  /// The platform's own description, e.g. `iOS Version 26.5.2 (Build 23F84)`.
  final String? osVersion;

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

    return BuildIdentity(
      appVersion: _truncate(app, 24),
      osVersion: _truncate(os, 32),
    );
  }

  /// The columns are VARCHAR(24) and VARCHAR(32), and the request validates
  /// `max:24` / `max:32`. An over-long string would fail validation and take
  /// the whole registration with it, so it is trimmed to fit rather than
  /// allowed to turn a version string into a failed device setup.
  static String? _truncate(String? value, int limit) {
    if (value == null || value.isEmpty) return null;

    return value.length <= limit ? value : value.substring(0, limit);
  }
}

/// Build-time configuration.
///
/// Values come from `--dart-define`, so a single binary can be pointed at
/// staging or production without a code change, and no secret is ever
/// committed to the repository.
class AppConfig {
  const AppConfig._();

  /// The emulator's alias for the host machine. Convenient for local work and
  /// wrong everywhere else: on a physical device 10.0.2.2 resolves to nothing,
  /// so a build that takes this default fails every request and — before the
  /// error text was corrected — told the tester they were offline while their
  /// phone had full signal.
  static const String _emulatorHost = 'http://10.0.2.2:8000/api/v1';

  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: _emulatorHost,
  );

  /// True when the build took the emulator default. Callers use this to warn
  /// rather than to fail: a developer running on an emulator is fine, and an
  /// installed build that took the default is broken, and the app cannot tell
  /// the two apart at runtime.
  static bool get usingEmulatorDefault => apiBaseUrl == _emulatorHost;

  static const String googleMapsApiKey = String.fromEnvironment('GOOGLE_MAPS_API_KEY');

  static const String appName = 'Fuel Intelligence Platform';

  /// Metro Manila centre — used when the user declines location access, so
  /// the map still shows something useful instead of an empty grey square.
  static const double fallbackLatitude = 14.5547;
  static const double fallbackLongitude = 121.0244;

  static const Duration connectTimeout = Duration(seconds: 10);
  static const Duration receiveTimeout = Duration(seconds: 30);

  /// The OCR endpoint runs a multi-pass Tesseract pipeline; it needs longer.
  static const Duration uploadTimeout = Duration(seconds: 60);

  static const double defaultRadiusKm = 5;
  static const double maxRadiusKm = 50;

  static const bool isProduction = bool.fromEnvironment('dart.vm.product');
}

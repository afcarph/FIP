/// Build-time configuration.
///
/// Values come from `--dart-define`, so a single binary can be pointed at
/// staging or production without a code change, and no secret is ever
/// committed to the repository.
class AppConfig {
  const AppConfig._();

  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1', // Android emulator → host
  );

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

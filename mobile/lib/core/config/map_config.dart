/// Where the base map comes from.
///
/// Deliberately mirrors the web client's `lib/map-config.ts`, including the
/// keyless fallback, so the two clients cannot disagree about which provider
/// FIP uses or about what "not configured" looks like.
///
/// The renderer (MapLibre) and the tiles are separate concerns: MapLibre
/// consumes any style JSON, so changing provider is a URL change rather than a
/// rewrite. Nothing outside this module knows the provider's name.
///
/// MapTiler style URLs look like:
///
///   https://api.maptiler.com/maps/streets-v2/style.json?key=YOUR_KEY
///
/// The key ships inside the app by necessity, as any client map key must, and
/// is protected by an app/domain allowlist in the provider's dashboard rather
/// than by secrecy. It is still supplied at build time so it is never
/// committed.
class MapConfig {
  const MapConfig._();

  /// MapLibre's own demo tiles. Keyless, low detail, explicitly not for
  /// production — it exists so the map renders during development and on a
  /// pilot build before anyone has provisioned a provider key.
  static const String developmentFallbackStyle = 'https://demotiles.maplibre.org/style.json';

  /// Supplied with `--dart-define=MAP_STYLE_URL=...`, the mobile counterpart of
  /// the web's NEXT_PUBLIC_MAP_STYLE_URL.
  static const String _configuredStyle = String.fromEnvironment('MAP_STYLE_URL');

  static String styleUrl() =>
      _configuredStyle.trim().isEmpty ? developmentFallbackStyle : _configuredStyle.trim();

  /// Whether a real provider is configured. The UI says so rather than
  /// silently showing a world map with no streets on it.
  static bool get isProviderConfigured => _configuredStyle.trim().isNotEmpty;

  /// Roughly the whole archipelago, for the initial view and for bounds checks.
  static const double philippinesLatitude = 12.8797;
  static const double philippinesLongitude = 121.774;
  static const double philippinesZoom = 5;

  /// Metro Manila, used when the user has not shared a location.
  static const double defaultLatitude = 14.5547;
  static const double defaultLongitude = 121.0244;
  static const double defaultZoom = 12;

  /// Whether a coordinate pair can be plotted.
  ///
  /// Rejects nulls, the 0,0 "null island" a failed fix leaves behind, and
  /// anything outside the Philippines — a vehicle in the Atlantic is a data
  /// error, and drawing it would stretch the map across the globe.
  static bool hasPlottableCoordinates(double? latitude, double? longitude) {
    if (latitude == null || longitude == null) return false;
    if (latitude == 0 && longitude == 0) return false;

    return latitude >= 4.5 && latitude <= 21.5 && longitude >= 116.0 && longitude <= 127.0;
  }
}

# FIP Mobile

Flutter client for the Fuel Intelligence Platform (iOS and Android).

## Running

```bash
flutter pub get

flutter run \
  --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1 \
  --dart-define=GOOGLE_MAPS_API_KEY=your-key
```

`10.0.2.2` is the Android emulator's alias for the host machine. On an iOS
simulator use `http://localhost:8000/api/v1`; on a physical device use your
machine's LAN address.

## Building

```bash
flutter build apk --release \
  --dart-define=API_BASE_URL=https://api.fip.ph/api/v1 \
  --dart-define=GOOGLE_MAPS_API_KEY=your-key

flutter build ipa --release \
  --dart-define=API_BASE_URL=https://api.fip.ph/api/v1 \
  --dart-define=GOOGLE_MAPS_API_KEY=your-key
```

No secret is committed: every environment value arrives through `--dart-define`.

## Structure

```
lib/
├── core/
│   ├── config/      Build-time configuration
│   ├── network/     Dio client with serialised token refresh, typed errors
│   ├── theme/       Material 3 themes + FipColors theme extension
│   └── utils/       Formatters shared with the web client's conventions
├── features/<feature>/presentation/   Screens
├── shared/
│   ├── providers/   Riverpod providers (auth, location, data)
│   └── widgets/     StatTile, ForecastCard, ErrorView, EmptyView
├── main.dart
└── router.dart      go_router with an auth redirect
```

## Platform setup

**Android** — `android/app/src/main/AndroidManifest.xml` needs:

```xml
<uses-permission android:name="android.permission.INTERNET"/>
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>
<uses-permission android:name="android.permission.CAMERA"/>
<uses-permission android:name="android.permission.USE_BIOMETRIC"/>

<meta-data android:name="com.google.android.geo.API_KEY" android:value="${MAPS_API_KEY}"/>
```

**iOS** — `ios/Runner/Info.plist` needs `NSLocationWhenInUseUsageDescription`,
`NSCameraUsageDescription`, `NSPhotoLibraryUsageDescription` and
`NSFaceIDUsageDescription`, each with a sentence explaining *why* the app is
asking — App Review rejects generic strings.

## Tests

```bash
flutter test
```

## Google Maps key

The map renders blank without one. Set it in `android/local.properties`, which
is git-ignored — never in `AndroidManifest.xml`, which is tracked and this
repository is public:

```properties
MAPS_API_KEY=your-key
```

Gradle reads it (or the `MAPS_API_KEY` environment variable, for CI) into a
manifest placeholder, defaulting to empty. An empty key builds fine and shows a
blank map, which is a better failure than a leaked key. The Dart side takes it
separately:

```bash
flutter build apk --debug --dart-define=GOOGLE_MAPS_API_KEY=your-key
```

The key must be authorised in Google Cloud for **Maps SDK for Android**, and if
it carries an Android restriction it needs an entry for each signing
certificate — the debug keystore's SHA-1 as well as the release one. Without
that, the SDK loads, draws the Google logo, and logs
`Authorization failure` with the fingerprint and package it expected.

## Building for a device

```bash
flutter build apk --debug        # Android; CI runs this on every push
flutter build ios --no-codesign  # iOS; needs CocoaPods installed
```

The application ID is `ph.fip.mobile`. The Android namespace stays
`ph.fip.fip_mobile` — it only names the R class and the Kotlin package on disk,
and is not externally visible.

Three settings in the generated Gradle files differ from what `flutter create`
writes, and all three are load-bearing:

- **`compileSdk = 36`** in `android/app/build.gradle.kts`, not
  `flutter.compileSdkVersion`. `sqflite_android` references
  `VERSION_CODES.BAKLAVA`, `Locale.of()` and `Thread.threadId()`, which
  android.jar only exposes at API 36.
- **Core library desugaring**, or `flutter_local_notifications` fails at
  `checkDebugAarMetadata`.
- **A plugin-module `compileSdk` override** in `android/build.gradle.kts`.
  Several plugins still declare 33 while their transitive AndroidX dependencies
  require 35+. It is registered *before* `subprojects { evaluationDependsOn(":app") }`,
  because `afterEvaluate` on an already-evaluated project throws.

Raising the pinned plugin versions may remove the need for the third; that has
not been tried.

**iOS is scaffolded but unverified.** CocoaPods was not available here, so
`flutter build ios` has never run against this plugin set — expect it to need
its own adjustments, and do not assume parity with Android.

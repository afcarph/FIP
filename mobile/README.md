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

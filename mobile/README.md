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

## Building for a device

**There are no `android/` or `ios/` directories in this repository yet.** The
app is library code and tests only, so `flutter test` and `flutter analyze`
pass — and CI passes with them — while `flutter build` has nothing to build
against. Generating the platform shells is a prerequisite for shipping, and it
fixes an application ID that becomes store identity, so it is a deliberate
decision rather than a routine `flutter create`.

A debug APK has been produced from a scratch copy, so the Dart and plugin code
is known to compile. Three changes to the generated Gradle files were needed
beyond what `flutter create` writes; whoever commits the shells will need them
from the first commit.

**1. `compileSdk = 36` in `android/app/build.gradle.kts`.** Not
`flutter.compileSdkVersion` — `sqflite_android` references
`VERSION_CODES.BAKLAVA`, `Locale.of()` and `Thread.threadId()`, which android.jar
only exposes at API 36.

**2. Core library desugaring**, or `flutter_local_notifications` fails at
`checkDebugAarMetadata`:

```kotlin
compileOptions {
    isCoreLibraryDesugaringEnabled = true
}
dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}
```

**3. A `compileSdk` override for the plugin modules** in
`android/build.gradle.kts`. Several still declare 33 while their transitive
AndroidX dependencies require 35+. It has to be registered *before* the
existing `subprojects { project.evaluationDependsOn(":app") }` block, or Gradle
fails with "Cannot run Project.afterEvaluate(Action) when the project is
already evaluated":

```kotlin
subprojects {
    afterEvaluate {
        extensions.findByName("android")?.let {
            (it as com.android.build.gradle.BaseExtension).compileSdkVersion(36)
        }
    }
}
```

Raising the pinned plugin versions may remove the need for the third item; that
has not been tried.

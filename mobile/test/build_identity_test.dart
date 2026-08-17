import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'package:fip_mobile/features/driver/data/build_identity.dart';

/// Build attribution.
///
/// The first pilot produced eight genuine positions against a device whose
/// `app_version` and `os_version` were both null, so no measurement could be
/// tied to a build. These tests pin the two things that make the fix useful:
/// the values are actually produced, and they fit the columns that store them.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    PackageInfo.setMockInitialValues(
      appName: 'FIP',
      packageName: 'ph.fip.fipMobile',
      version: '1.0.0',
      buildNumber: '1',
      buildSignature: '',
    );
  });

  test('the app version carries both version and build number', () async {
    // "1.0.0 (1)" is what a tester reads off a store listing or an about
    // screen; the stored value has to be quotable against it.
    final identity = await BuildIdentity.resolve();

    expect(identity.appVersion, '1.0.0+1');
  });

  test('an os version is resolved', () async {
    final identity = await BuildIdentity.resolve();

    expect(identity.osVersion, isNotNull);
    expect(identity.osVersion, isNotEmpty);
  });

  test('the values fit the columns that store them', () async {
    // user_devices.app_version is VARCHAR(24) and os_version VARCHAR(32), and
    // the request validates max:24 / max:32. Exceeding either would fail
    // validation and take the whole device registration with it — a version
    // string must never be able to break setup.
    final identity = await BuildIdentity.resolve();

    expect(identity.appVersion!.length, lessThanOrEqualTo(24));
    expect(identity.osVersion!.length, lessThanOrEqualTo(32));
  });

  test('a build number is optional', () async {
    PackageInfo.setMockInitialValues(
      appName: 'FIP',
      packageName: 'ph.fip.fipMobile',
      version: '2.1.0',
      buildNumber: '',
      buildSignature: '',
    );

    expect((await BuildIdentity.resolve()).appVersion, '2.1.0');
  });

  test('an over-long version is trimmed rather than allowed to fail setup', () async {
    PackageInfo.setMockInitialValues(
      appName: 'FIP',
      packageName: 'ph.fip.fipMobile',
      version: '1.0.0-release-candidate-with-an-absurdly-long-suffix',
      buildNumber: '999',
      buildSignature: '',
    );

    final identity = await BuildIdentity.resolve();

    expect(identity.appVersion!.length, 24);
  });

  group('what the handset is called', () {
    // Registrations arrived either unnamed or as "FIP mobile" — the same for
    // every phone in the fleet — so the owner's device list could not tell two
    // apart on the one screen where a session gets revoked.

    test('a maker and a model read as one name', () {
      expect(BuildIdentity.describeAndroid('Google', 'Pixel 8'), 'Google Pixel 8');
    });

    test('a model that already carries the brand is not said twice', () {
      expect(BuildIdentity.describeAndroid('OnePlus', 'OnePlus 12'), 'OnePlus 12');
    });

    test('a bare model code keeps its maker, which is what makes it mean anything', () {
      expect(BuildIdentity.describeAndroid('samsung', 'SM-S911B'), 'Samsung SM-S911B');
    });

    test('a lowercase maker is not shouted back at the owner', () {
      expect(BuildIdentity.describeAndroid('xiaomi', 'Redmi Note 13'), 'Xiaomi Redmi Note 13');
    });

    test('a missing model falls back to the maker rather than to nothing', () {
      expect(BuildIdentity.describeAndroid('Google', ''), 'Google');
    });

    test('a missing maker still yields the model', () {
      expect(BuildIdentity.describeAndroid(null, 'Pixel 8'), 'Pixel 8');
    });

    test('nothing at all is null rather than an empty name', () {
      // Null is honest and the server leaves the column alone; an empty string
      // would be stored and render as a blank row, which is the bug.
      expect(BuildIdentity.describeAndroid(null, null), isNull);
      expect(BuildIdentity.describeAndroid('  ', '  '), isNull);
    });
  });
}

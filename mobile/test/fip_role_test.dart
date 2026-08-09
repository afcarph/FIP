import 'package:flutter_test/flutter_test.dart';

import 'package:fip_mobile/core/auth/fip_role.dart';
import 'package:fip_mobile/shared/providers/app_providers.dart';

/// Role resolution and what each role is shown.
///
/// None of this is authorisation — the server decides that independently, and
/// these tests do not pretend otherwise. What they pin is that a role is never
/// shown a destination whose data it cannot get, because a tab that always
/// returns an empty state teaches people the app is broken.
void main() {
  AuthState signedInAs(List<String> roles) =>
      AuthState(user: const {'id': 1}, roles: roles, isLoading: false);

  group('role resolution', () {
    test('each role resolves to itself', () {
      expect(signedInAs(['super_admin']).fipRole, FipRole.superAdmin);
      expect(signedInAs(['system_admin']).fipRole, FipRole.superAdmin);
      expect(signedInAs(['company_manager']).fipRole, FipRole.companyAdmin);
      expect(signedInAs(['fleet_manager']).fipRole, FipRole.fleetManager);
      expect(signedInAs(['driver']).fipRole, FipRole.driver);
      expect(signedInAs(['viewer']).fipRole, FipRole.viewer);
    });

    test('an unknown or empty role set resolves to unknown', () {
      expect(signedInAs([]).fipRole, FipRole.unknown);
      expect(signedInAs(['guest']).fipRole, FipRole.unknown);
    });

    test('a manager who also drives keeps the manager view', () {
      // Otherwise the single-vehicle screen would hide most of their job.
      expect(signedInAs(['fleet_manager', 'driver']).fipRole, FipRole.fleetManager);
      expect(signedInAs(['company_manager', 'driver']).fipRole, FipRole.companyAdmin);
    });

    test('a platform admin outranks every company role', () {
      expect(
        signedInAs(['super_admin', 'company_manager', 'driver']).fipRole,
        FipRole.superAdmin,
      );
    });
  });

  group('landing', () {
    test('a platform admin does not land in one company’s operations', () {
      expect(FipRole.superAdmin.home, '/platform');
    });

    test('every other role lands on home', () {
      for (final role in [
        FipRole.companyAdmin,
        FipRole.fleetManager,
        FipRole.driver,
        FipRole.viewer,
      ]) {
        expect(role.home, '/home');
      }
    });
  });

  group('navigation', () {
    List<String> paths(FipRole role) => role.destinations.map((d) => d.path).toList();

    test('driver navigation is a single vehicle, not a fleet', () {
      expect(paths(FipRole.driver), ['/home', '/my-vehicle', '/alerts', '/more']);
      expect(paths(FipRole.driver), isNot(contains('/fleet')));
      expect(paths(FipRole.driver), isNot(contains('/live-map')));
    });

    test('viewer navigation offers no map', () {
      // A viewer holds no devices permission, so a live map would be a
      // permanently empty screen.
      expect(paths(FipRole.viewer), ['/home', '/fleet', '/alerts', '/reports', '/more']);
      expect(paths(FipRole.viewer), isNot(contains('/live-map')));
    });

    test('operational roles share the same five destinations', () {
      const expected = ['/home', '/fleet', '/live-map', '/alerts', '/more'];

      expect(paths(FipRole.fleetManager), expected);
      expect(paths(FipRole.companyAdmin), expected);
    });

    test('platform admin navigation is platform-shaped', () {
      expect(paths(FipRole.superAdmin), ['/platform', '/fleet', '/system', '/more']);
    });

    test('every role has a More destination to reach settings and sign out', () {
      for (final role in FipRole.values) {
        expect(paths(role), contains('/more'), reason: '$role must be able to sign out');
      }
    });

    test('no role is shown more than five destinations', () {
      // Beyond five a bottom bar stops being scannable.
      for (final role in FipRole.values) {
        expect(role.destinations.length, lessThanOrEqualTo(5), reason: '$role');
      }
    });

    test('every role has a label', () {
      for (final role in FipRole.values) {
        expect(role.label, isNotEmpty);
      }
    });
  });
}

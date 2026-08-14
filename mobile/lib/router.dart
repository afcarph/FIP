import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import 'features/assistant/presentation/assistant_screen.dart';
import 'features/auth/presentation/login_screen.dart';
import 'features/doe/presentation/doe_history_screen.dart';
import 'features/doe/presentation/doe_home_screen.dart';
import 'features/doe/presentation/doe_search_screen.dart';
import 'features/doe/presentation/doe_settings_screen.dart';
import 'features/expenses/presentation/expenses_screen.dart';
import 'features/map/presentation/map_screen.dart';
import 'features/scanner/presentation/scanner_screen.dart';
import 'features/fleet/presentation/alerts_screen.dart';
import 'core/auth/fip_role.dart';
import 'features/admin/presentation/platform_home_screen.dart';
import 'features/admin/presentation/privacy_settings_screen.dart';
import 'features/admin/presentation/system_screen.dart';
import 'features/admin/presentation/users_admin_screen.dart';
import 'features/driver/presentation/driver_home_screen.dart';
import 'features/driver/presentation/my_vehicle_screen.dart';
import 'features/expenses/presentation/receipt_scan_screen.dart';
import 'features/reports/presentation/reports_screen.dart';
import 'features/fleet/presentation/fip_home_screen.dart';
import 'features/fleet/presentation/fleet_screen.dart';
import 'features/fleet/presentation/fuel_intelligence_screen.dart';
import 'features/fleet/presentation/live_map_screen.dart';
import 'features/fleet/presentation/more_screen.dart';
import 'features/fleet/presentation/vehicle_detail_screen.dart';
import 'features/vehicles/presentation/vehicles_screen.dart';
import 'shared/widgets/fip/fip_chrome.dart';
import 'features/fleet/data/fleet_providers.dart';
import 'shared/providers/app_providers.dart';

/// Router with an auth redirect.
///
/// The map is browsable without an account — that is the app's shop window —
/// so it sits in the public set alongside login.
final routerProvider = Provider<GoRouter>((ref) {
  final auth = ref.watch(authProvider);

  return GoRouter(
    // The DOE section is the UAT surface and needs no account. Landing on
    // /dashboard would bounce a signed-out tester to /login with no route to
    // the screens they were asked to test.
    initialLocation: '/home',
    redirect: (context, state) {
      // Hold the splash until the stored token has been checked, otherwise a
      // signed-in user is briefly bounced to the login screen.
      if (auth.isLoading) return null;

      const publicRoutes = {'/login', '/register', '/map'};
      final isPublic =
          publicRoutes.contains(state.matchedLocation) ||
          state.matchedLocation.startsWith('/doe');

      if (!auth.isAuthenticated && !isPublic) return '/login';
      if (auth.isAuthenticated && state.matchedLocation == '/login') return auth.fipRole.home;

      return null;
    },
    routes: [
      GoRoute(path: '/login', builder: (context, state) => const LoginScreen()),

      // The DOE section. Public, like the map: every /fuel endpoint is served
      // without a token, so requiring an account to read the department's own
      // published figures would impose friction the API does not.
      ShellRoute(
        builder: (context, state, child) => _DoeScaffold(child: child),
        routes: [
          GoRoute(path: '/doe', builder: (context, state) => const DoeHomeScreen()),
          GoRoute(path: '/doe/search', builder: (context, state) => const DoeSearchScreen()),
          GoRoute(path: '/doe/history', builder: (context, state) => const DoeHistoryScreen()),
          GoRoute(path: '/doe/settings', builder: (context, state) => const DoeSettingsScreen()),
        ],
      ),
      // The FIP fleet shell. Five destinations, fleet-first.
      ShellRoute(
        builder: (context, state, child) => _FipScaffold(child: child),
        routes: [
          // One route, two audiences. A driver has one vehicle and no business
          // seeing the rest of the fleet, so the role decides the screen rather
          // than the fleet home hiding rows.
          GoRoute(path: '/home', builder: (context, state) => const _RoleHome()),
          GoRoute(path: '/fleet', builder: (context, state) => const FleetScreen()),
          GoRoute(
            path: '/fleet/:id',
            builder: (context, state) => VehicleDetailScreen(
              vehicleId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
          ),
          GoRoute(path: '/live-map', builder: (context, state) => const LiveMapScreen()),
          GoRoute(path: '/alerts', builder: (context, state) => const AlertsScreen()),
          GoRoute(path: '/more', builder: (context, state) => const MoreScreen()),
          // App settings live in the DOE folder for historical reasons but are
          // app-level (API host, appearance). Routed inside the FIP shell so
          // opening them does not strand the user in the DOE navigation.
          GoRoute(path: '/settings', builder: (context, state) => const DoeSettingsScreen()),
          GoRoute(path: '/platform', builder: (context, state) => const PlatformHomeScreen()),
          GoRoute(path: '/system', builder: (context, state) => const SystemScreen()),
          GoRoute(path: '/reports', builder: (context, state) => const ReportsScreen()),
          GoRoute(path: '/admin/users', builder: (context, state) => const UsersAdminScreen()),
          GoRoute(path: '/admin/settings', builder: (context, state) => const PrivacySettingsScreen()),
          GoRoute(path: '/my-vehicle', builder: (context, state) => const MyVehicleScreen()),
          // The receipt reader. Distinct from /scan, which reads a station's
          // price board for other drivers rather than recording a fill-up.
          GoRoute(path: '/scan-receipt', builder: (context, state) => const ReceiptScanScreen()),
          GoRoute(path: '/map', builder: (context, state) => const MapScreen()),
          GoRoute(path: '/scan', builder: (context, state) => const ScannerScreen()),
          GoRoute(path: '/expenses', builder: (context, state) => const ExpensesScreen()),
          GoRoute(path: '/vehicles', builder: (context, state) => const VehiclesScreen()),
          GoRoute(path: '/assistant', builder: (context, state) => const AssistantScreen()),
        ],
      ),
      GoRoute(path: '/fuel', builder: (context, state) => const FuelIntelligenceScreen()),
    ],
    errorBuilder:
        (context, state) => Scaffold(
          body: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(LucideIcons.circleAlert, size: 40),
                const SizedBox(height: 12),
                Text('Page not found', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 4),
                Text(state.matchedLocation, style: Theme.of(context).textTheme.bodySmall),
                const SizedBox(height: 16),
                FilledButton(
                  onPressed: () => context.go('/home'),
                  child: const Text('Back to home'),
                ),
              ],
            ),
          ),
        ),
  );
});

/// Bottom navigation for the DOE section.
///
/// Kept separate from the FIP shell: these screens read the department's
/// published weekly figures and are browsable signed out, while every FIP
/// destination needs an account. One bar covering both would offer a
/// signed-out tester tabs that bounce to login. The Fleet destination is the
/// way back into the app.
class _DoeScaffold extends StatelessWidget {
  const _DoeScaffold({required this.child});

  final Widget child;

  static const _destinations = [
    (path: '/doe', icon: LucideIcons.house, label: 'Home'),
    (path: '/doe/search', icon: LucideIcons.search, label: 'Search'),
    (path: '/doe/history', icon: LucideIcons.chartLine, label: 'History'),
    (path: '/doe/settings', icon: LucideIcons.settings, label: 'Settings'),
    (path: '/home', icon: LucideIcons.truck, label: 'Fleet'),
  ];

  @override
  Widget build(BuildContext context) {
    final location = GoRouterState.of(context).matchedLocation;

    // Longest match first, so /doe/search does not select Home.
    var index = 0;
    for (var i = 0; i < _destinations.length; i++) {
      if (location == _destinations[i].path) index = i;
    }

    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: (selected) => context.go(_destinations[selected].path),
        destinations: [
          for (final destination in _destinations)
            NavigationDestination(icon: Icon(destination.icon), label: destination.label),
        ],
      ),
    );
  }
}

/// The FIP shell.
///
/// Home, Fleet, Map, Alerts, More — named for what an operator is doing
/// rather than for the data behind each screen. The alert count rides on the
/// Alerts tab because it is the only destination that can be urgent.
class _FipScaffold extends ConsumerWidget {
  const _FipScaffold({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final location = GoRouterState.of(context).matchedLocation;
    final alerts = ref.watch(fleetAlertsProvider);

    return Scaffold(
      body: child,
      bottomNavigationBar: FipBottomNav(
        location: location,
        destinations: ref.watch(authProvider).fipRole.destinations,
        alertCount: alerts.maybeWhen(data: (list) => list.length, orElse: () => 0),
      ),
    );
  }
}

/// Home resolves by role: drivers see their own vehicle, everyone else sees
/// the fleet. A driver who also holds a fleet role keeps the fleet view.
class _RoleHome extends ConsumerWidget {
  const _RoleHome();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return switch (ref.watch(authProvider).fipRole) {
      // One vehicle, one job.
      FipRole.driver => const DriverHomeScreen(),

      // A platform administrator reaching /home directly still belongs on the
      // platform view rather than in one tenant's operations.
      FipRole.superAdmin => const PlatformHomeScreen(),

      // Company admin, fleet manager and viewer share the operational surface.
      // The viewer's copy is narrower because the endpoints it cannot reach
      // return 403 and render as honest empty states, not because it is a
      // different screen.
      _ => const FipHomeScreen(),
    };
  }
}

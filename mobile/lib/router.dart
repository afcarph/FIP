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
import 'features/dashboard/presentation/dashboard_screen.dart';
import 'features/expenses/presentation/expenses_screen.dart';
import 'features/map/presentation/map_screen.dart';
import 'features/scanner/presentation/scanner_screen.dart';
import 'features/vehicles/presentation/vehicles_screen.dart';
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
    initialLocation: '/doe',
    redirect: (context, state) {
      // Hold the splash until the stored token has been checked, otherwise a
      // signed-in user is briefly bounced to the login screen.
      if (auth.isLoading) return null;

      const publicRoutes = {'/login', '/register', '/map'};
      final isPublic =
          publicRoutes.contains(state.matchedLocation) ||
          state.matchedLocation.startsWith('/doe');

      if (!auth.isAuthenticated && !isPublic) return '/login';
      if (auth.isAuthenticated && state.matchedLocation == '/login') return '/dashboard';

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
      ShellRoute(
        builder: (context, state, child) => _AppScaffold(child: child),
        routes: [
          GoRoute(path: '/dashboard', builder: (context, state) => const DashboardScreen()),
          GoRoute(path: '/map', builder: (context, state) => const MapScreen()),
          GoRoute(path: '/scan', builder: (context, state) => const ScannerScreen()),
          GoRoute(path: '/expenses', builder: (context, state) => const ExpensesScreen()),
          GoRoute(path: '/vehicles', builder: (context, state) => const VehiclesScreen()),
          GoRoute(path: '/assistant', builder: (context, state) => const AssistantScreen()),
        ],
      ),
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
                  onPressed: () => context.go('/dashboard'),
                  child: const Text('Back to dashboard'),
                ),
              ],
            ),
          ),
        ),
  );
});

/// Bottom navigation for the DOE section.
///
/// Separate from [_AppScaffold] rather than folded into it: these four screens
/// read the department's published weekly figures and are browsable signed
/// out, while the app's own destinations all need an account. One bar covering
/// both would offer a signed-out tester three tabs that bounce to login.
class _DoeScaffold extends StatelessWidget {
  const _DoeScaffold({required this.child});

  final Widget child;

  static const _destinations = [
    (path: '/doe', icon: LucideIcons.house, label: 'Home'),
    (path: '/doe/search', icon: LucideIcons.search, label: 'Search'),
    (path: '/doe/history', icon: LucideIcons.chartLine, label: 'History'),
    (path: '/doe/settings', icon: LucideIcons.settings, label: 'Settings'),
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

/// Bottom navigation shell.
///
/// Five destinations, with the scanner in the centre as a raised action — it
/// is the app's signature interaction and deserves the most reachable spot
/// on a phone held one-handed.
class _AppScaffold extends StatelessWidget {
  const _AppScaffold({required this.child});

  final Widget child;

  static const _destinations = [
    (path: '/dashboard', icon: LucideIcons.house, label: 'Home'),
    (path: '/map', icon: LucideIcons.map, label: 'Map'),
    (path: '/scan', icon: LucideIcons.scanLine, label: 'Scan'),
    (path: '/expenses', icon: LucideIcons.receipt, label: 'Expenses'),
    (path: '/assistant', icon: LucideIcons.sparkles, label: 'Advisor'),
  ];

  @override
  Widget build(BuildContext context) {
    final location = GoRouterState.of(context).matchedLocation;
    final index = _destinations.indexWhere((destination) => location.startsWith(destination.path));

    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: index < 0 ? 0 : index,
        onDestinationSelected: (selected) => context.go(_destinations[selected].path),
        destinations: [
          for (final destination in _destinations)
            NavigationDestination(
              icon: Icon(destination.icon),
              selectedIcon: Icon(destination.icon, color: Theme.of(context).colorScheme.primary),
              label: destination.label,
            ),
        ],
      ),
    );
  }
}

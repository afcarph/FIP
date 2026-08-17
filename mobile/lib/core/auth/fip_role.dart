import 'package:flutter/material.dart';

import '../../shared/providers/app_providers.dart';

/// Who is signed in, in product terms.
///
/// The server is the authority on what a role may do; this exists only to
/// decide what to *draw*. Navigation visibility is not authorisation — every
/// screen behind these destinations calls an endpoint that enforces the same
/// rules independently, and a role that reaches a screen it should not see
/// gets an honest empty state from a 403 rather than data.
enum FipRole { superAdmin, companyAdmin, fleetManager, driver, viewer, unknown }

/// A bottom-navigation destination.
typedef FipDestination = ({String path, IconData icon, String label});

extension FipRoleResolution on AuthState {
  /// The highest-privilege role the account holds.
  ///
  /// Ordered deliberately: an account with both `fleet_manager` and `driver`
  /// is a manager who also drives, and showing them the single-vehicle screen
  /// would hide most of their job.
  FipRole get fipRole {
    if (hasRole(['super_admin', 'system_admin'])) return FipRole.superAdmin;
    if (hasRole(['company_manager'])) return FipRole.companyAdmin;
    if (hasRole(['fleet_manager'])) return FipRole.fleetManager;
    if (hasRole(['viewer'])) return FipRole.viewer;
    if (hasRole(['driver'])) return FipRole.driver;

    return FipRole.unknown;
  }
}

extension FipRoleNavigation on FipRole {
  String get label => switch (this) {
    FipRole.superAdmin => 'Platform administrator',
    FipRole.companyAdmin => 'Company administrator',
    FipRole.fleetManager => 'Fleet manager',
    FipRole.driver => 'Driver',
    FipRole.viewer => 'Viewer',
    FipRole.unknown => 'Signed in',
  };

  /// Where this role lands after sign-in.
  ///
  /// A platform administrator does not get the operational fleet dashboard:
  /// they have no single fleet, and defaulting them into one company's
  /// operations misrepresents what they are looking at.
  String get home => this == FipRole.superAdmin ? '/platform' : '/home';

  /// Bottom navigation, per role.
  ///
  /// Each set is the shortest list that covers the role's job. Destinations a
  /// role cannot use are absent rather than disabled — a tab that always
  /// refuses teaches people to ignore tabs.
  List<FipDestination> get destinations => switch (this) {
    // No Map: viewers hold no devices permission, so a live map would show a
    // permanent empty state.
    FipRole.viewer => const [
      (path: '/home', icon: Icons.speed_rounded, label: 'Home'),
      (path: '/fleet', icon: Icons.local_shipping_rounded, label: 'Fleet'),
      (path: '/alerts', icon: Icons.notifications_rounded, label: 'Alerts'),
      (path: '/reports', icon: Icons.summarize_rounded, label: 'Reports'),
      (path: '/more', icon: Icons.grid_view_rounded, label: 'More'),
    ],

    // One vehicle, so "Fleet" would be a list of one and "Map" a single pin.
    FipRole.driver => const [
      (path: '/home', icon: Icons.speed_rounded, label: 'Home'),
      (path: '/my-vehicle', icon: Icons.local_shipping_rounded, label: 'Vehicle'),
      (path: '/alerts', icon: Icons.notifications_rounded, label: 'Alerts'),
      (path: '/more', icon: Icons.grid_view_rounded, label: 'More'),
    ],

    FipRole.superAdmin => const [
      (path: '/platform', icon: Icons.dashboard_rounded, label: 'Dashboard'),
      (path: '/fleet', icon: Icons.local_shipping_rounded, label: 'Fleet'),
      (path: '/system', icon: Icons.monitor_heart_rounded, label: 'System'),
      (path: '/more', icon: Icons.grid_view_rounded, label: 'More'),
    ],

    // Company admin and fleet manager run the same operational surface; they
    // differ in what More offers, not in how they work day to day.
    _ => const [
      (path: '/home', icon: Icons.speed_rounded, label: 'Home'),
      (path: '/fleet', icon: Icons.local_shipping_rounded, label: 'Fleet'),
      (path: '/live-map', icon: Icons.map_rounded, label: 'Map'),
      (path: '/alerts', icon: Icons.notifications_rounded, label: 'Alerts'),
      (path: '/more', icon: Icons.grid_view_rounded, label: 'More'),
    ],
  };
}

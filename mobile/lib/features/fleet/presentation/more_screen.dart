import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/fip_role.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../../tracking/presentation/tracking_providers.dart';

/// More — everything that is not the fleet.
///
/// DOE market prices live here. They are a useful reference, not the job:
/// putting them on Home made the product look like a price browser, which is
/// what this restructure exists to correct.
class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authProvider);
    final name = [
      auth.user?['first_name'],
      auth.user?['last_name'],
    ].whereType<String>().join(' ').trim();
    final email = (auth.user?['email'] as String?) ?? '';
    final role = auth.fipRole;
    final isAdmin = role == FipRole.superAdmin || role == FipRole.companyAdmin;
    final isDriver = role == FipRole.driver;
    final isViewer = role == FipRole.viewer;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: ListView(
          padding: const EdgeInsets.only(bottom: FipSpace.xxl),
          children: [
            const FipHeader(title: 'More', subtitle: 'Tools, market data and settings'),

            const SectionHeader(title: 'Fuel intelligence', icon: Icons.insights_rounded),
            _Group(
              children: [
                if (!isDriver)
                  _Tile(
                    icon: Icons.insights_rounded,
                    title: 'Fuel intelligence',
                    subtitle: 'Levels, efficiency and loss detection',
                    onTap: () => context.push('/fuel'),
                  ),
                if (!isViewer) ...[
                  _Tile(
                    icon: Icons.document_scanner_rounded,
                    title: 'Scan a receipt',
                    subtitle: 'Record a fill-up from a photo',
                    onTap: () => context.push('/scan'),
                  ),
                  _Tile(
                    icon: Icons.receipt_long_rounded,
                    title: 'Expenses',
                    subtitle: 'Fuel spend and history',
                    onTap: () => context.push('/expenses'),
                  ),
                ],
              ],
            ),

            // The DOE section, kept in full but no longer the front door.
            const SectionHeader(
              title: 'Market prices',
              subtitle: 'Department of Energy published figures',
              icon: Icons.public_rounded,
            ),
            _Group(
              children: [
                _Tile(
                  icon: Icons.local_offer_rounded,
                  title: 'Latest DOE prices',
                  subtitle: 'Weekly published pump prices',
                  onTap: () => context.go('/doe'),
                ),
                _Tile(
                  icon: Icons.search_rounded,
                  title: 'Search prices',
                  subtitle: 'By region, brand and fuel type',
                  onTap: () => context.go('/doe/search'),
                ),
                _Tile(
                  icon: Icons.show_chart_rounded,
                  title: 'Price history',
                  subtitle: 'Published weekly trend',
                  onTap: () => context.go('/doe/history'),
                ),
                _Tile(
                  icon: Icons.map_rounded,
                  title: 'Station map',
                  subtitle: 'Nearby stations and prices',
                  onTap: () => context.push('/map'),
                ),
              ],
            ),

            const SectionHeader(title: 'Assistant', icon: Icons.auto_awesome_rounded),
            _Group(
              children: [
                _Tile(
                  icon: Icons.auto_awesome_rounded,
                  title: 'Fuel advisor',
                  subtitle: 'Ask about spend, savings and consumption',
                  onTap: () => context.push('/assistant'),
                ),
              ],
            ),

            if (isAdmin) ...[
              const SectionHeader(
                title: 'Administration',
                subtitle: 'Company and platform',
                icon: Icons.admin_panel_settings_rounded,
              ),
              _Group(
                children: [
                  _Tile(
                    icon: Icons.group_rounded,
                    title: 'Users',
                    subtitle: 'People in your company',
                    onTap: () => context.push('/admin/users'),
                  ),
                  if (role == FipRole.superAdmin)
                    _Tile(
                      icon: Icons.shield_rounded,
                      title: 'Privacy & retention',
                      subtitle: 'Location retention period',
                      onTap: () => context.push('/admin/settings'),
                    ),
                ],
              ),
            ],

            SectionHeader(
              title: 'Account',
              subtitle: email.isEmpty ? null : email,
              icon: Icons.person_rounded,
            ),
            _Group(
              children: [
                _Tile(
                  icon: Icons.settings_rounded,
                  title: 'Settings',
                  subtitle: name.isEmpty ? 'Appearance, API and privacy' : name,
                  onTap: () => context.push('/settings'),
                ),
                _Tile(
                  icon: Icons.logout_rounded,
                  title: 'Sign out',
                  subtitle: 'End this session on this device',
                  destructive: true,
                  onTap: () => _confirmSignOut(context, ref),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Confirm before ending the session.
///
/// Signing out clears the stored token and, on a driver's phone, stops
/// location reporting — worth one tap of confirmation rather than losing a
/// session to a mis-tap in a list of navigation rows.
Future<void> _confirmSignOut(BuildContext context, WidgetRef ref) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Sign out?'),
      content: const Text(
        'You will need to sign in again to see your fleet. Location sharing stops immediately.',
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Cancel')),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('Sign out'),
        ),
      ],
    ),
  );

  if (confirmed != true) return;

  // Stop reporting before the session goes: a device that keeps sampling after
  // sign-out would be collecting location for a user who has just left.
  ref.read(trackingControllerProvider.notifier).disable();
  await ref.read(authProvider.notifier).signOut();

  // The router's redirect sends the app to /login once the session clears, so
  // there is deliberately no navigation call here to race with it.
}

class _Group extends StatelessWidget {
  const _Group({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
      child: FipCard(
        padding: EdgeInsets.zero,
        child: Column(
          children: [
            for (var i = 0; i < children.length; i++) ...[
              if (i > 0) const Divider(height: 1, indent: 54),
              children[i],
            ],
          ],
        ),
      ),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.destructive = false,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  /// Tints the row so an irreversible action does not look like navigation.
  final bool destructive;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final tint = destructive ? scheme.error : scheme.primary;

    return ListTile(
      onTap: onTap,
      leading: Container(
        width: 34,
        height: 34,
        decoration: BoxDecoration(
          color: tint.withValues(alpha: 0.09),
          borderRadius: BorderRadius.circular(FipRadius.control),
        ),
        child: Icon(icon, size: 17, color: tint),
      ),
      title: Text(
        title,
        style: Theme.of(
          context,
        ).textTheme.bodyLarge?.copyWith(fontWeight: FontWeight.w600, color: destructive ? tint : null),
      ),
      subtitle: Text(subtitle, style: FipType.caption(context)),
      trailing: destructive
          ? null
          : const Icon(Icons.chevron_right_rounded, size: 18),
    );
  }
}

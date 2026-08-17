import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/fip_spacing.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/metric_card.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../data/platform_providers.dart';

/// Platform overview, for administrators who run FIP rather than a fleet.
///
/// Deliberately not the operational fleet dashboard: a platform administrator
/// has no single fleet, and defaulting them into one company's Fleet Pulse
/// would present one tenant's numbers as if they were the platform's.
class PlatformHomeScreen extends ConsumerWidget {
  const PlatformHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final health = ref.watch(systemHealthProvider);
    final firstName = ref.watch(authProvider).user?['first_name'] as String?;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(systemHealthProvider),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.only(bottom: FipSpace.xxl),
            children: [
              FipHeader(
                title: firstName == null ? 'Platform' : 'Platform, $firstName',
                subtitle: 'FIP across all companies',
              ),

              const SectionHeader(
                title: 'System health',
                subtitle: 'From /admin/system',
                icon: Icons.monitor_heart_rounded,
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: health.when(
                  loading: () => const SizedBox(
                    height: 96,
                    child: Center(child: CircularProgressIndicator()),
                  ),
                  error: (error, _) => FipEmptyState(
                    icon: Icons.cloud_off_rounded,
                    title: 'Could not read system health',
                    message: '$error',
                    action: 'Retry',
                    onAction: () => ref.invalidate(systemHealthProvider),
                  ),
                  data: (snapshot) => _HealthGrid(snapshot: snapshot),
                ),
              ),

              const SectionHeader(
                title: 'Administration',
                icon: Icons.admin_panel_settings_rounded,
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: FipCard(
                  padding: EdgeInsets.zero,
                  child: Column(
                    children: [
                      _AdminTile(
                        icon: Icons.shield_rounded,
                        title: 'Privacy & data retention',
                        subtitle: 'Location retention period',
                        onTap: () => context.push('/admin/settings'),
                      ),
                      const Divider(height: 1, indent: 54),
                      _AdminTile(
                        icon: Icons.monitor_heart_rounded,
                        title: 'System detail',
                        subtitle: 'Health checks and discovery',
                        onTap: () => context.go('/system'),
                      ),
                    ],
                  ),
                ),
              ),

              // Companies has no endpoint. Rather than fabricate a list or
              // quietly omit the section, the gap is stated: a platform
              // administrator should know the view is missing, not assume the
              // platform has one company.
              const SectionHeader(title: 'Companies', icon: Icons.apartment_rounded),
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: FipEmptyState(
                  icon: Icons.apartment_rounded,
                  title: 'No companies endpoint yet',
                  message:
                      'The API exposes users, system health and audit logs, but nothing that lists companies or their subscription tiers. This section stays empty until it does.',
                  compact: true,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _HealthGrid extends StatelessWidget {
  const _HealthGrid({required this.snapshot});

  final PlatformHealth snapshot;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: MetricCard(
                label: 'Status',
                value: snapshot.status.toUpperCase(),
                icon: Icons.favorite_rounded,
                tone: snapshot.isHealthy ? null : Theme.of(context).colorScheme.error,
                compact: true,
              ),
            ),
            const SizedBox(width: FipSpace.gap),
            Expanded(
              child: MetricCard(
                label: 'Database',
                value: snapshot.databaseLatencyMs?.toStringAsFixed(0) ?? '—',
                unit: snapshot.databaseLatencyMs == null ? null : 'ms',
                icon: Icons.storage_rounded,
                compact: true,
              ),
            ),
          ],
        ),
        const SizedBox(height: FipSpace.gap),
        Row(
          children: [
            Expanded(
              child: MetricCard(
                label: 'Scheduler',
                value: snapshot.schedulerStatus ?? '—',
                icon: Icons.schedule_rounded,
                hint: snapshot.schedulerLastRun,
                compact: true,
              ),
            ),
            const SizedBox(width: FipSpace.gap),
            Expanded(
              child: MetricCard(
                label: 'Disk used',
                value: snapshot.diskUsedPercent?.toStringAsFixed(0) ?? '—',
                unit: snapshot.diskUsedPercent == null ? null : '%',
                icon: Icons.sd_storage_rounded,
                compact: true,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _AdminTile extends StatelessWidget {
  const _AdminTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return ListTile(
      onTap: onTap,
      leading: Container(
        width: 34,
        height: 34,
        decoration: BoxDecoration(
          color: scheme.primary.withValues(alpha: 0.09),
          borderRadius: BorderRadius.circular(FipRadius.control),
        ),
        child: Icon(icon, size: 17, color: scheme.primary),
      ),
      title: Text(
        title,
        style: Theme.of(context).textTheme.bodyLarge?.copyWith(fontWeight: FontWeight.w600),
      ),
      subtitle: Text(subtitle, style: FipType.caption(context)),
      trailing: const Icon(Icons.chevron_right_rounded, size: 18),
    );
  }
}

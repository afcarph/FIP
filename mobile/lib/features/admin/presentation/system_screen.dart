import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/fip_spacing.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../data/platform_providers.dart';

/// System health detail, from `/admin/system`.
///
/// Gated server-side on `audit.view` or a platform admin role. A role without
/// it reaches this screen only by typing the path, and gets the refusal state
/// rather than data — which is the point: the tab being hidden is convenience,
/// the 403 is the control.
class SystemScreen extends ConsumerWidget {
  const SystemScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final health = ref.watch(systemHealthProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(systemHealthProvider),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.only(bottom: FipSpace.xxl),
            children: [
              const FipHeader(title: 'System', subtitle: 'Platform health'),
              const SectionHeader(title: 'Checks', icon: Icons.monitor_heart_rounded),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
                child: health.when(
                  loading: () => const SizedBox(
                    height: 120,
                    child: Center(child: CircularProgressIndicator()),
                  ),
                  error: (error, _) => FipEmptyState(
                    icon: Icons.lock_rounded,
                    title: 'System health unavailable',
                    message:
                        'This requires platform administration access. $error',
                    action: 'Retry',
                    onAction: () => ref.invalidate(systemHealthProvider),
                  ),
                  data: (snapshot) => FipCard(
                    child: Column(
                      children: [
                        _Row(label: 'Overall', value: snapshot.status),
                        _Row(
                          label: 'Database',
                          value: snapshot.databaseLatencyMs == null
                              ? '—'
                              : '${snapshot.databaseLatencyMs!.toStringAsFixed(0)} ms',
                        ),
                        _Row(label: 'Scheduler', value: snapshot.schedulerStatus ?? '—'),
                        _Row(label: 'Last run', value: snapshot.schedulerLastRun ?? '—'),
                        _Row(
                          label: 'Disk used',
                          value: snapshot.diskUsedPercent == null
                              ? '—'
                              : '${snapshot.diskUsedPercent!.toStringAsFixed(0)}%',
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(child: Text(label, style: FipType.caption(context))),
          Flexible(
            child: Text(
              value,
              style: Theme.of(
                context,
              ).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}

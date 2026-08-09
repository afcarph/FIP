import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/fip_spacing.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/metric_card.dart';
import '../../../shared/widgets/fip/section_header.dart';

/// Location retention, read from the admin settings API.
///
/// Read-only on mobile deliberately. Retention is a business and privacy
/// decision with an audit trail behind it; changing it from a phone, possibly
/// while driving, is not a workflow worth building. The web console owns the
/// edit, and this shows what is in force and whether anyone approved it.
class PrivacySettingsScreen extends ConsumerWidget {
  const PrivacySettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final settings = ref.watch(privacySettingsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Privacy & retention'),
        leading: const FipBackButton(fallback: '/more'),
      ),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(privacySettingsProvider),
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.only(bottom: FipSpace.xxl),
          children: [
            const SectionHeader(
              title: 'Vehicle location retention',
              icon: Icons.shield_rounded,
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
              child: settings.when(
                loading: () => const SizedBox(
                  height: 120,
                  child: Center(child: CircularProgressIndicator()),
                ),
                error: (error, _) => FipEmptyState(
                  icon: Icons.lock_rounded,
                  title: 'Settings unavailable',
                  message: 'This requires settings administration access. $error',
                  action: 'Retry',
                  onAction: () => ref.invalidate(privacySettingsProvider),
                ),
                data: (data) => Column(
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: MetricCard(
                            label: 'Retention',
                            value: '${data['days'] ?? '—'}',
                            unit: 'days',
                            compact: true,
                          ),
                        ),
                        const SizedBox(width: FipSpace.gap),
                        Expanded(
                          child: MetricCard(
                            label: 'Status',
                            value: ((data['status'] as String?) ?? '—')
                                .replaceAll('_', ' ')
                                .toUpperCase(),
                            tone: data['status'] == 'approved'
                                ? null
                                : Theme.of(context).colorScheme.error,
                            compact: true,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: FipSpace.gap),
                    FipCard(
                      child: Text(
                        data['status'] == 'approved'
                            ? 'An administrator set this period deliberately. Location history older than it is deleted by the scheduled retention job.'
                            : 'This period has not been approved. It is in force, but nobody has chosen it — the web console is where it gets set.',
                        style: FipType.caption(context),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

final privacySettingsProvider = FutureProvider<Map<String, dynamic>>((ref) async {
  final auth = ref.watch(authProvider);
  if (!auth.isAuthenticated) return const {};

  final api = ref.read(apiClientProvider);
  final response = await api.get<dynamic>('/admin/settings/privacy');
  final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

  return (data as Map<String, dynamic>)['location_retention'] as Map<String, dynamic>? ?? const {};
});

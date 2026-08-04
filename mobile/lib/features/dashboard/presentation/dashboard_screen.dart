import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/error_view.dart';
import '../../../shared/widgets/forecast_card.dart';
import '../../../shared/widgets/stat_tile.dart';

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dashboard = ref.watch(dashboardProvider);
    final auth = ref.watch(authProvider);

    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(dashboardProvider),
          child: dashboard.when(
            loading: () => const _DashboardSkeleton(),
            error:
                (error, _) =>
                    ErrorView(error: error, onRetry: () => ref.invalidate(dashboardProvider)),
            data:
                (data) =>
                    _DashboardBody(data: data, firstName: auth.user?['first_name'] as String?),
          ),
        ),
      ),
    );
  }
}

class _DashboardBody extends ConsumerWidget {
  const _DashboardBody({required this.data, this.firstName});

  final Map<String, dynamic> data;
  final String? firstName;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summary = data['summary'] as Map<String, dynamic>? ?? {};
    final savings = data['savings'] as Map<String, dynamic>? ?? {};
    final forecasts = (data['forecasts'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
    final vehicles = (data['vehicles'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
    final maintenance =
        (data['maintenance_due'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();

    return CustomScrollView(
      // Always scrollable so pull-to-refresh works even on a short page.
      physics: const AlwaysScrollableScrollPhysics(),
      slivers: [
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        '${_greeting()}${firstName != null ? ', $firstName' : ''}',
                        style: Theme.of(
                          context,
                        ).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w600),
                      ),
                    ),
                    // AuthNotifier.signOut and POST /auth/logout both existed and
                    // neither was reachable: nothing in the app called it, so a
                    // session could not be ended on a shared or lost device.
                    IconButton(
                      icon: const Icon(LucideIcons.logOut, size: 20),
                      tooltip: 'Sign out',
                      onPressed: () => _confirmSignOut(context, ref),
                    ),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  'Your fuel spend and this week’s outlook',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
        ),

        // Headline figures
        SliverPadding(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
          sliver: SliverGrid.count(
            crossAxisCount: 2,
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            // 1.25, not 1.55: at the tighter ratio a tile with a hint is
            // shorter than its own content, so every such tile scaled its
            // text down to avoid overflowing. StatTile still guards against
            // overflow for large text scales; this stops it triggering at the
            // default one.
            childAspectRatio: 1.25,
            children: [
              StatTile(
                label: 'Spent this month',
                value: Formatters.compactCurrency(summary['total_cost'] as num?),
                icon: LucideIcons.fuel,
              ),
              StatTile(
                label: 'Fuel purchased',
                value: Formatters.litres(summary['total_litres'] as num?),
                icon: LucideIcons.droplet,
                hint: '${summary['fill_ups'] ?? 0} fill-ups',
              ),
              StatTile(
                label: 'Efficiency',
                value: Formatters.efficiency(summary['avg_km_per_litre'] as num?),
                icon: LucideIcons.gauge,
                accent: StatAccent.success,
              ),
              StatTile(
                label: 'Missed savings',
                value: Formatters.compactCurrency(savings['potential_savings'] as num?),
                icon: LucideIcons.piggyBank,
                accent: StatAccent.danger,
                hint:
                    savings['savings_pct'] != null
                        ? '${(savings['savings_pct'] as num).toStringAsFixed(1)}% of spend'
                        : null,
              ),
            ],
          ),
        ),

        if (forecasts.isNotEmpty) ...[
          _SectionHeader(
            title: 'This week’s forecast',
            action: 'History',
            onAction: () => context.go('/forecasts'),
          ),
          SliverToBoxAdapter(
            child: SizedBox(
              height: 190,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 20),
                itemCount: forecasts.length,
                separatorBuilder: (_, __) => const SizedBox(width: 12),
                itemBuilder: (context, index) {
                  final forecast = forecasts[index];

                  return SizedBox(
                    width: 280,
                    child: ForecastCard(
                      fuelType: forecast['fuel_type'] as String? ?? 'Fuel',
                      direction: forecast['direction'] as String? ?? 'no_change',
                      changeAmount: (forecast['change_amount'] as num?)?.toDouble() ?? 0,
                      confidence: (forecast['confidence'] as num?)?.toDouble() ?? 0,
                      narrative: forecast['narrative'] as String?,
                    ),
                  );
                },
              ),
            ),
          ),
        ],

        if (vehicles.isNotEmpty) ...[
          _SectionHeader(
            title: 'Your vehicles',
            action: 'All',
            onAction: () => context.go('/vehicles'),
          ),
          SliverPadding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            sliver: SliverList.separated(
              itemCount: vehicles.length.clamp(0, 3),
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, index) => _VehicleRow(vehicle: vehicles[index]),
            ),
          ),
        ],

        if (maintenance.isNotEmpty) ...[
          const _SectionHeader(title: 'Coming up'),
          SliverPadding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            sliver: SliverList.separated(
              itemCount: maintenance.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, index) => _MaintenanceRow(item: maintenance[index]),
            ),
          ),
        ],

        const SliverToBoxAdapter(child: SizedBox(height: 32)),
      ],
    );
  }

  Future<void> _confirmSignOut(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder:
          (context) => AlertDialog(
            title: const Text('Sign out?'),
            content: const Text('You will need to sign in again to see your fill-ups.'),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Sign out'),
              ),
            ],
          ),
    );

    if (confirmed ?? false) {
      await ref.read(authProvider.notifier).signOut();
    }
  }

  static String _greeting() {
    final hour = DateTime.now().hour;

    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({required this.title, this.action, this.onAction});

  final String title;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return SliverToBoxAdapter(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 12, 10),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              title,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
            ),
            if (action != null) TextButton(onPressed: onAction, child: Text(action!)),
          ],
        ),
      ),
    );
  }
}

class _VehicleRow extends StatelessWidget {
  const _VehicleRow({required this.vehicle});

  final Map<String, dynamic> vehicle;

  @override
  Widget build(BuildContext context) {
    final deviation = vehicle['efficiency_deviation_pct'] as num?;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        leading: CircleAvatar(
          backgroundColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.12),
          child: Icon(LucideIcons.car, size: 18, color: Theme.of(context).colorScheme.primary),
        ),
        title: Text(
          vehicle['name'] as String? ?? 'Vehicle',
          style: const TextStyle(fontWeight: FontWeight.w500),
        ),
        subtitle: Text(
          '${vehicle['plate_number']} · ${Formatters.distance(vehicle['odometer'] as num?)}',
        ),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              Formatters.efficiency(vehicle['avg_km_per_litre'] as num?),
              style: const TextStyle(
                fontWeight: FontWeight.w600,
                fontFeatures: [FontFeature.tabularFigures()],
              ),
            ),
            if (deviation != null)
              Text(
                Formatters.percent(deviation),
                style: TextStyle(
                  fontSize: 11,
                  // A drop below baseline is the actionable case, so only that
                  // gets a warning colour.
                  color:
                      deviation < -5
                          ? context.fipColors.priceUp
                          : Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _MaintenanceRow extends StatelessWidget {
  const _MaintenanceRow({required this.item});

  final Map<String, dynamic> item;

  @override
  Widget build(BuildContext context) {
    final isOverdue = item['status'] == 'overdue';

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
        leading: Icon(
          LucideIcons.wrench,
          size: 18,
          color: isOverdue ? Theme.of(context).colorScheme.error : context.fipColors.warning,
        ),
        title: Text(item['service'] as String? ?? 'Service'),
        subtitle: Text(item['vehicle'] as String? ?? ''),
        trailing: Chip(
          label: Text(
            isOverdue
                ? 'Overdue'
                : Formatters.date(DateTime.tryParse(item['due_at'] as String? ?? '')),
            style: const TextStyle(fontSize: 11),
          ),
          backgroundColor:
              isOverdue
                  ? Theme.of(context).colorScheme.errorContainer
                  : context.fipColors.warning.withValues(alpha: 0.15),
          visualDensity: VisualDensity.compact,
        ),
      ),
    );
  }
}

class _DashboardSkeleton extends StatelessWidget {
  const _DashboardSkeleton();

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Container(
          height: 28,
          width: 180,
          color: Theme.of(context).colorScheme.surfaceContainerHighest,
        ),
        const SizedBox(height: 24),
        GridView.count(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          crossAxisCount: 2,
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: 1.55,
          children: List.generate(4, (_) => Card(child: Container(color: Colors.transparent))),
        ),
      ],
    );
  }
}

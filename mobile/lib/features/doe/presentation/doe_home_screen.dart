import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../data/doe_models.dart';
import '../data/doe_providers.dart';
import 'doe_widgets.dart';

/// Latest DOE update, a region selector, and the current week's prices.
class DoeHomeScreen extends ConsumerWidget {
  const DoeHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final health = ref.watch(doeHealthProvider);
    final region = ref.watch(selectedRegionProvider);
    final prices = ref.watch(doeLatestProvider(DoeQuery(region: region, perPage: 40)));

    return Scaffold(
      appBar: AppBar(title: const Text('Fuel Prices')),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(doeHealthProvider);
          ref.invalidate(doeLatestProvider);
        },
        child: health.when(
          loading: () => const DoeLoading(label: 'Loading latest report'),
          error: (error, _) => ListView(
            // Inside a scrollable so pull-to-refresh still works when the
            // first load failed — otherwise the only way to retry an offline
            // start is to restart the app.
            children: [DoeError(error: error, onRetry: () => ref.invalidate(doeHealthProvider))],
          ),
          data: (data) => ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _LatestCard(health: data),
              if (data.latestReports.isNotEmpty) ...[
                const SizedBox(height: 16),
                DoeFreshnessCard(reports: data.latestReports),
              ],
              const SizedBox(height: 16),
              _RegionSelector(regions: data.regions, selected: region),
              const SizedBox(height: 16),
              Row(
                children: [
                  Text('Latest prices', style: Theme.of(context).textTheme.titleMedium),
                  const Spacer(),
                  TextButton.icon(
                    onPressed: () => context.go('/doe/search'),
                    icon: const Icon(LucideIcons.search, size: 16),
                    label: const Text('Search'),
                  ),
                ],
              ),
              prices.when(
                loading: () => const DoeLoading(),
                error: (error, _) =>
                    DoeError(error: error, onRetry: () => ref.invalidate(doeLatestProvider)),
                data: (rows) => rows.isEmpty
                    ? const DoeEmpty(
                        title: 'No prices for this region',
                        hint: 'Try another region, or pull down to refresh.',
                      )
                    : Card(
                        clipBehavior: Clip.antiAlias,
                        child: Column(
                          children: [
                            for (final price in rows.take(30))
                              DoePriceTile(key: ValueKey(price.id), price: price),
                          ],
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

class _LatestCard extends StatelessWidget {
  const _LatestCard({required this.health});

  final ImportHealth health;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final newest = health.latestReports.isEmpty
        ? null
        : (health.latestReports.toList()
              ..sort((a, b) => b.coverageStart.compareTo(a.coverageStart)))
            .first;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(LucideIcons.fileText, size: 18, color: theme.colorScheme.primary),
                const SizedBox(width: 8),
                Text('Latest DOE update', style: theme.textTheme.labelLarge),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              newest?.coverageLabel ?? 'Nothing imported yet',
              style: theme.textTheme.headlineSmall,
            ),
            if (newest != null)
              Text('${newest.region} · ${newest.areasCount} areas', style: theme.textTheme.bodySmall),
            const Divider(height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                _Stat(label: 'Reports', value: '${health.reportsTotal}'),
                _Stat(label: 'Price records', value: '${health.pricesTotal}'),
                _Stat(label: 'Regions', value: '${health.regionsTotal}'),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Text(value, style: Theme.of(context).textTheme.titleMedium),
      Text(label, style: Theme.of(context).textTheme.bodySmall),
    ],
  );
}

class _RegionSelector extends ConsumerWidget {
  const _RegionSelector({required this.regions, required this.selected});

  final List<String> regions;
  final String? selected;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (regions.isEmpty) return const SizedBox.shrink();

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          ChoiceChip(
            label: const Text('All regions'),
            selected: selected == null,
            onSelected: (_) => ref.read(selectedRegionProvider.notifier).state = null,
          ),
          for (final region in regions) ...[
            const SizedBox(width: 8),
            ChoiceChip(
              label: Text(region),
              selected: selected == region,
              onSelected: (_) => ref.read(selectedRegionProvider.notifier).state = region,
            ),
          ],
        ],
      ),
    );
  }
}

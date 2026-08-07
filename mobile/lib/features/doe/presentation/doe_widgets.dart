
import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/network/api_exception.dart';
import '../data/doe_models.dart';

/// The states every DOE screen can be in.
///
/// Kept together because the distinction between them is the point: an empty
/// result and a failed request look identical if both render "no data", and a
/// tester cannot then tell a bug from a quiet week.

class DoeLoading extends StatelessWidget {
  const DoeLoading({super.key, this.label = 'Loading'});

  final String label;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.all(32),
    child: Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        const CircularProgressIndicator(),
        const SizedBox(height: 12),
        Text(label, style: Theme.of(context).textTheme.bodySmall),
      ],
    ),
  );
}

class DoeEmpty extends StatelessWidget {
  const DoeEmpty({super.key, required this.title, this.hint});

  final String title;
  final String? hint;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.all(32),
    child: Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Icon(LucideIcons.inbox, size: 36, color: Theme.of(context).hintColor),
        const SizedBox(height: 12),
        Text(title, style: Theme.of(context).textTheme.titleSmall, textAlign: TextAlign.center),
        if (hint != null) ...[
          const SizedBox(height: 6),
          Text(
            hint!,
            style: Theme.of(context).textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
        ],
      ],
    ),
  );
}

/// A failed request.
///
/// Distinguishes losing the network from the API answering with an error: the
/// remedy differs, and a tester needs to be able to say which one they saw.
class DoeError extends StatelessWidget {
  const DoeError({super.key, required this.error, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final apiError = error is ApiException ? error as ApiException : null;
    final unreachable = apiError?.code == 'unreachable';

    return Padding(
      padding: const EdgeInsets.all(32),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            unreachable ? LucideIcons.wifiOff : LucideIcons.triangleAlert,
            size: 36,
            color: Colors.amber.shade700,
          ),
          const SizedBox(height: 12),
          Text(
            unreachable ? 'Could not reach the server' : 'The API returned an error',
            style: Theme.of(context).textTheme.titleSmall,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 6),
          Text(
            apiError?.message ?? error.toString(),
            style: Theme.of(context).textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
          if (onRetry != null) ...[
            const SizedBox(height: 16),
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(LucideIcons.refreshCw, size: 16),
              label: const Text('Retry'),
            ),
          ],
        ],
      ),
    );
  }
}

/// One published price row.
class DoePriceTile extends StatelessWidget {
  const DoePriceTile({super.key, required this.price, this.showArea = true});

  final DoePrice price;
  final bool showArea;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListTile(
      dense: true,
      title: Row(
        children: [
          Expanded(
            child: Text(
              showArea ? price.area : price.product,
              style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
              overflow: TextOverflow.ellipsis,
            ),
          ),
          Text(
            price.rangeLabel,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
      subtitle: Row(
        children: [
          Expanded(
            child: Text(
              [
                if (showArea) price.product,
                // The DOE prints this as its own column rather than deriving
                // it, so it is labelled rather than left blank.
                price.isOverall ? 'All brands' : price.brand ?? '—',
                if (price.province != null) price.province!,
              ].join(' · '),
              style: theme.textTheme.bodySmall,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (price.commonPrice != null)
            Text(
              'Common ₱${price.commonPrice!.toStringAsFixed(2)}',
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
    );
  }
}

/// How current each region's newest report is.
class DoeFreshnessCard extends StatelessWidget {
  const DoeFreshnessCard({super.key, required this.reports});

  final List<DoeReport> reports;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final rows = [...reports]..sort((a, b) => a.region.compareTo(b.region));

    final newestWeek = rows.fold<String?>(
      null,
      (newest, report) =>
          newest == null || report.coverageStart.compareTo(newest) > 0
              ? report.coverageStart
              : newest,
    );

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Data freshness', style: theme.textTheme.titleMedium),
            const SizedBox(height: 2),
            Text(
              'Age is counted from the end of the week each report covers.',
              style: theme.textTheme.bodySmall,
            ),
            for (final report in rows) ...[
              const Divider(height: 20),
              _FreshnessRow(
                report: report,
                // Said out loud rather than left to be inferred from two
                // coverage labels. A region a week behind the other is the
                // reading most likely to be reported as a bug in the numbers.
                isBehindNewest:
                    newestWeek != null && report.coverageStart.compareTo(newestWeek) < 0,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _FreshnessRow extends StatelessWidget {
  const _FreshnessRow({required this.report, required this.isBehindNewest});

  final DoeReport report;
  final bool isBehindNewest;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final freshness = Freshness.of(report);

    final tone = switch (freshness.status) {
      FreshnessStatus.current => Colors.green,
      FreshnessStatus.stale => Colors.amber,
      FreshnessStatus.behind => Colors.red,
    };

    // shade800 on a translucent fill is legible on white and nearly invisible
    // on the dark theme's near-black surface, where the fill barely lifts the
    // background at all.
    final isDark = theme.brightness == Brightness.dark;
    final onTone = isDark ? tone.shade200 : tone.shade800;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    report.region,
                    style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                  ),
                  Text(report.coverageLabel, style: theme.textTheme.bodySmall),
                ],
              ),
            ),
            Text(
              freshness.ageLabel,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
            const SizedBox(width: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(
                color: tone.withValues(alpha: isDark ? 0.24 : 0.15),
                borderRadius: BorderRadius.circular(999),
              ),
              child: Text(
                freshness.statusLabel,
                style: theme.textTheme.labelSmall?.copyWith(color: onTone),
              ),
            ),
          ],
        ),
        if (isBehindNewest) ...[
          const SizedBox(height: 4),
          Text(
            'One publication behind the newest week held.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: isDark ? Colors.amber.shade300 : Colors.amber.shade800,
            ),
          ),
        ],
      ],
    );
  }
}

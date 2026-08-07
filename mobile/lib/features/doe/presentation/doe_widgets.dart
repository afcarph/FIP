
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

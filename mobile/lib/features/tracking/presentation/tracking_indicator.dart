import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/tracking_service.dart';
import 'tracking_indicator_copy.dart';
import 'tracking_providers.dart';

/// A persistent banner saying whether location is being collected.
///
/// It is always present rather than appearing only when something is wrong.
/// An indicator that shows up only on failure teaches a driver that no banner
/// means nothing is happening, which is exactly backwards: the state worth
/// being unmissable is the one where their position is being shared.
class TrackingIndicator extends ConsumerWidget {
  const TrackingIndicator({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = ref.watch(trackingControllerProvider);
    final controller = ref.read(trackingControllerProvider.notifier);
    final copy = TrackingCopy.forStatus(status);
    final scheme = Theme.of(context).colorScheme;

    final (background, foreground, icon) = switch (copy.tone) {
      TrackingTone.active => (
        scheme.primaryContainer,
        scheme.onPrimaryContainer,
        Icons.my_location,
      ),
      TrackingTone.attention => (
        scheme.errorContainer,
        scheme.onErrorContainer,
        Icons.location_disabled,
      ),
      TrackingTone.inactive => (
        scheme.surfaceContainerHighest,
        scheme.onSurfaceVariant,
        Icons.location_off,
      ),
    };

    return Semantics(
      liveRegion: true,
      label: '${copy.headline}. ${copy.detail}',
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(12)),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 20, color: foreground),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    copy.headline,
                    style: Theme.of(
                      context,
                    ).textTheme.titleSmall?.copyWith(color: foreground, fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    copy.detail,
                    style: Theme.of(
                      context,
                    ).textTheme.bodySmall?.copyWith(color: foreground.withValues(alpha: 0.9)),
                  ),
                  if (copy.action != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: TextButton(
                        onPressed: () => switch (status) {
                          TrackingStatus.permissionDenied => controller.enable(),
                          _ => controller.openSettings(status),
                        },
                        style: TextButton.styleFrom(
                          foregroundColor: foreground,
                          padding: EdgeInsets.zero,
                          minimumSize: const Size(0, 32),
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        ),
                        child: Text(copy.action!),
                      ),
                    ),
                ],
              ),
            ),
            // The switch is the only control that starts collection, so it is
            // never pre-enabled — sharing begins because the driver said so.
            Switch(
              value: copy.isActive,
              onChanged: (wanted) => wanted ? controller.enable() : controller.disable(),
            ),
          ],
        ),
      ),
    );
  }
}

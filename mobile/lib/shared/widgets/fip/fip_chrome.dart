import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/fip_role.dart';
import '../../../core/theme/fip_spacing.dart';

/// Shared chrome: the branded header, the empty state, and the bottom bar.

/// The FIP header.
///
/// The mark appears on every top-level screen at a consistent size. It is the
/// only place the logo is used in-app — repeating it inside cards would turn
/// branding into noise.
class FipHeader extends StatelessWidget {
  const FipHeader({super.key, required this.title, this.subtitle, this.actions, this.showMark = true});

  final String title;
  final String? subtitle;
  final List<Widget>? actions;
  final bool showMark;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(FipSpace.page, FipSpace.lg, FipSpace.md, FipSpace.sm),
      child: Row(
        children: [
          if (showMark) ...[
            Image.asset(
              'assets/images/fip-mark.png',
              width: 34,
              height: 34,
              semanticLabel: 'Fuel Intelligence Platform',
            ),
            const SizedBox(width: FipSpace.md),
          ],
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                if (subtitle != null)
                  Text(
                    subtitle!,
                    style: FipType.caption(context),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
              ],
            ),
          ),
          ...?actions,
        ],
      ),
    );
  }
}

/// A back control that always has somewhere to go.
///
/// `context.pop()` alone is not enough: a screen reached by a deep link, or
/// after a cold start, has nothing on the stack, and the button would either
/// do nothing or throw. Falling back to a named parent means the user is never
/// stranded on a screen with no exit — which is exactly what happened when
/// these screens were navigated to with `go` instead of `push`.
class FipBackButton extends StatelessWidget {
  const FipBackButton({super.key, required this.fallback});

  /// Where to go when there is no history to pop.
  final String fallback;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      icon: const Icon(Icons.arrow_back_rounded),
      tooltip: 'Back',
      onPressed: () => context.canPop() ? context.pop() : context.go(fallback),
    );
  }
}

/// An empty state that still looks like FIP.
///
/// Empty is the normal condition for a fleet that has not been set up yet, so
/// it gets a designed surface rather than centred grey text. It always says
/// what would fill the space, because "no data" without a cause reads as a
/// broken screen.
class FipEmptyState extends StatelessWidget {
  const FipEmptyState({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.action,
    this.onAction,
    this.compact = false,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? action;
  final VoidCallback? onAction;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      width: double.infinity,
      padding: EdgeInsets.symmetric(
        horizontal: FipSpace.xl,
        vertical: compact ? FipSpace.xl : FipSpace.xxl,
      ),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(FipRadius.card),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Column(
        // Without this the Column takes MainAxisSize.max, which is invisible
        // inside a scroll view where height is unbounded but fills the screen
        // inside a Stack — the map's empty state grew to full height and
        // covered the summary card behind it.
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              color: scheme.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(FipRadius.control),
            ),
            child: Icon(icon, size: 22, color: scheme.primary),
          ),
          const SizedBox(height: FipSpace.md),
          Text(
            title,
            textAlign: TextAlign.center,
            style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: FipSpace.xs),
          Text(
            message,
            textAlign: TextAlign.center,
            style: FipType.caption(context),
          ),
          if (action != null) ...[
            const SizedBox(height: FipSpace.lg),
            OutlinedButton(onPressed: onAction, child: Text(action!)),
          ],
        ],
      ),
    );
  }
}

/// The FIP bottom navigation.
///
/// Five destinations named for what an operator is trying to do, not for the
/// data behind them. Alerts carries a count because it is the only
/// destination that can be urgent.
class FipBottomNav extends StatelessWidget {
  const FipBottomNav({
    super.key,
    required this.location,
    required this.destinations,
    this.alertCount = 0,
  });

  final String location;

  /// Supplied by the caller from the signed-in role. Visibility is a drawing
  /// decision only — every destination leads to a screen whose API enforces
  /// the same rules server-side.
  final List<FipDestination> destinations;

  final int alertCount;

  @override
  Widget build(BuildContext context) {
    // Longest-prefix match, so /fleet/12 keeps Fleet selected rather than
    // falling through to Home.
    var index = 0;
    var best = 0;
    for (var i = 0; i < destinations.length; i++) {
      final path = destinations[i].path;
      if (location.startsWith(path) && path.length > best) {
        best = path.length;
        index = i;
      }
    }

    // Screens reached from More but routed at the top level would otherwise
    // fall through to Home and highlight the wrong tab.
    const underMore = ['/settings', '/scan', '/expenses', '/assistant', '/vehicles', '/map'];

    if (underMore.any((path) => location == path || location.startsWith('$path/'))) {
      final more = destinations.indexWhere((d) => d.path == '/more');
      if (more >= 0) index = more;
    }

    if (index >= destinations.length) index = 0;

    return NavigationBar(
      selectedIndex: index,
      onDestinationSelected: (selected) => context.go(destinations[selected].path),
      destinations: [
        for (final destination in destinations)
          NavigationDestination(
            icon: destination.path == '/alerts' && alertCount > 0
                ? Badge.count(count: alertCount, child: Icon(destination.icon))
                : Icon(destination.icon),
            label: destination.label,
          ),
      ],
    );
  }
}

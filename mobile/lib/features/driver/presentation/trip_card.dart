import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/widgets/fip/status_badge.dart';
import '../data/trip_providers.dart';

/// One trip the driver has been given, and the single thing they can do to it.
///
/// The action comes from the server's own `can` list, so this offers exactly
/// what the API would accept: a dispatched trip can be started, one already
/// under way can be closed, and nothing else appears. Cancelling and
/// dispatching are not here because they are not the driver's to make — they
/// decide whether work happens, and a driver reports what did.
class TripCard extends ConsumerStatefulWidget {
  const TripCard({required this.trip, super.key});

  final DriverTrip trip;

  @override
  ConsumerState<TripCard> createState() => _TripCardState();
}

class _TripCardState extends ConsumerState<TripCard> {
  final _odometer = TextEditingController();
  bool _asking = false;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _odometer.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final trip = widget.trip;
    final typed = _odometer.text.trim();
    final reading = typed.isEmpty ? null : int.tryParse(typed);

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final actions = ref.read(tripActionsProvider);

      if (trip.canStart) {
        await actions.start(trip.id, odometer: reading);
      } else {
        await actions.complete(trip.id, odometer: reading);
      }

      if (mounted) setState(() => _asking = false);
    } catch (error) {
      // The server refuses a closing reading below the opening one, among
      // other things. Its wording is better than anything invented here.
      if (mounted) setState(() => _error = _message(error));
    } finally {
      if (mounted) {
        setState(() => _busy = false);
        _odometer.clear();
      }
    }
  }

  /// The server's own sentence, not the exception wrapping it.
  ///
  /// Stringifying an ApiException puts its class name and error code in front
  /// of the message — a driver was shown
  /// "ApiException(odometer_decreased): The closing odometer…" when the useful
  /// half was the second sentence. The typed field carries exactly what the
  /// API meant a person to read.
  String _message(Object error) {
    if (error is ApiException) return error.message;

    return 'That could not be saved. Try again in a moment.';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final trip = widget.trip;
    final starting = trip.canStart;

    return FipCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(trip.reference, style: theme.textTheme.titleMedium),
              ),
              // The plain badge rather than the severity one: FipSeverity is
              // the alert scale, and a trip in progress is not a warning.
              StatusBadge(
                label: trip.statusLabel,
                color: trip.status == 'in_progress'
                    ? theme.colorScheme.primary
                    : theme.hintColor,
              ),
            ],
          ),
          const SizedBox(height: FipSpace.xs),

          Text(trip.route, style: theme.textTheme.bodyMedium),

          if (trip.purpose != null || trip.plateNumber != null) ...[
            const SizedBox(height: FipSpace.xs),
            Text(
              [trip.plateNumber, trip.purpose].where((v) => v != null).join(' · '),
              style: theme.textTheme.bodySmall?.copyWith(color: theme.hintColor),
            ),
          ],

          if (_error != null) ...[
            const SizedBox(height: FipSpace.sm),
            Text(
              _error!,
              style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
            ),
          ],

          if (_asking) ...[
            const SizedBox(height: FipSpace.md),
            TextField(
              controller: _odometer,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'Odometer now (km)',
                // Said plainly: a driver who cannot see the dash reading should
                // still be able to close the trip rather than leave it open.
                helperText: 'Optional — leave blank if you cannot read it',
                isDense: true,
              ),
            ),
            const SizedBox(height: FipSpace.sm),
            Row(
              children: [
                TextButton(
                  onPressed: _busy ? null : () => setState(() => _asking = false),
                  child: const Text('Back'),
                ),
                const Spacer(),
                FilledButton(
                  onPressed: _busy ? null : _submit,
                  child: Text(
                    _busy
                        ? 'Saving…'
                        : starting
                            ? 'Start trip'
                            : 'Complete trip',
                  ),
                ),
              ],
            ),
          ] else ...[
            const SizedBox(height: FipSpace.md),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: _busy ? null : () => setState(() => _asking = true),
                child: Text(starting ? 'Start this trip' : 'Complete this trip'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

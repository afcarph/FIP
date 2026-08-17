import 'package:flutter_test/flutter_test.dart';

import 'package:fip_mobile/features/tracking/data/tracking_service.dart';
import 'package:fip_mobile/features/tracking/presentation/tracking_indicator_copy.dart';

/// What the driver is told, for every state the service can be in.
///
/// The wording is the privacy control here — the mechanism stops collection,
/// but only this tells the person carrying the phone that it was happening.
void main() {
  test('every tracking state has copy', () {
    // Guards the switch: a new TrackingStatus with no wording would otherwise
    // reach a driver as an empty banner.
    for (final status in TrackingStatus.values) {
      final copy = TrackingCopy.forStatus(status);

      expect(copy.headline, isNotEmpty, reason: 'no headline for $status');
      expect(copy.detail, isNotEmpty, reason: 'no detail for $status');
    }
  });

  test('only running reads as active', () {
    for (final status in TrackingStatus.values) {
      expect(
        TrackingCopy.forStatus(status).isActive,
        status == TrackingStatus.running,
        reason: '$status must not imply location is being collected',
      );
    }
  });

  test('the active state says collection is happening and when it stops', () {
    final copy = TrackingCopy.forStatus(TrackingStatus.running);

    expect(copy.tone, TrackingTone.active);
    expect(copy.headline.toLowerCase(), contains('on'));
    // The foreground-only promise is the part a driver most needs, and it has
    // to survive somebody editing the wording later.
    expect(copy.detail.toLowerCase(), contains('while this app is open'));
    expect(copy.detail.toLowerCase(), contains('stops'));
  });

  test('the inactive state says nothing is being collected', () {
    final copy = TrackingCopy.forStatus(TrackingStatus.idle);

    expect(copy.tone, TrackingTone.inactive);
    expect(copy.detail.toLowerCase(), contains('no position'));
    expect(copy.action, isNull, reason: 'idle is a valid state, not a problem to fix');
  });

  test('a denied permission offers the in-app prompt', () {
    final copy = TrackingCopy.forStatus(TrackingStatus.permissionDenied);

    expect(copy.tone, TrackingTone.attention);
    expect(copy.action, 'Allow access');
  });

  test('a permanently denied permission points at device settings instead', () {
    // The OS will not show the prompt again, so offering it would be a button
    // that visibly does nothing.
    final copy = TrackingCopy.forStatus(TrackingStatus.permissionDeniedForever);

    expect(copy.action, 'Open settings');
    expect(copy.detail.toLowerCase(), contains('device settings'));
  });

  test('location services off is distinguished from permission denied', () {
    final services = TrackingCopy.forStatus(TrackingStatus.locationServicesOff);
    final permission = TrackingCopy.forStatus(TrackingStatus.permissionDenied);

    expect(services.headline, isNot(permission.headline));
    expect(services.action, 'Open settings');
  });

  test('a revoked device offers no dead-end button', () {
    // Only an administrator can restore a revoked device, so there is nothing
    // the driver could usefully tap.
    final copy = TrackingCopy.forStatus(TrackingStatus.deviceRevoked);

    expect(copy.tone, TrackingTone.attention);
    expect(copy.action, isNull);
    expect(copy.detail.toLowerCase(), contains('administrator'));
  });

  test('an unregistered device points the driver at setup, not at an admin', () {
    // A driver is authorised to register their own device and attach it to
    // their assigned vehicle, so the old "ask your administrator" copy was
    // both wrong and a dead end.
    final copy = TrackingCopy.forStatus(TrackingStatus.deviceNotRegistered);

    expect(copy.action, 'Set up device');
    expect(copy.detail.toLowerCase(), isNot(contains('administrator')));
    expect(copy.detail.toLowerCase(), contains('assigned vehicle'));
  });

  test('no state claims background collection', () {
    for (final status in TrackingStatus.values) {
      final text = TrackingCopy.forStatus(status).detail.toLowerCase();

      expect(text, isNot(contains('background')));
      expect(text, isNot(contains('always')));
    }
  });
}

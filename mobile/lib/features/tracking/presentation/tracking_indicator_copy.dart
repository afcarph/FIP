import '../data/tracking_service.dart';

/// How each tracking state is described to the driver.
///
/// Separated from the widget so the wording is testable without a platform
/// channel, and so the full set of states is visible in one place. A driver
/// being told "location is on" deserves the same care as a driver being told
/// why it is off.
enum TrackingTone { active, inactive, attention }

class TrackingCopy {
  const TrackingCopy({
    required this.headline,
    required this.detail,
    required this.tone,
    this.action,
  });

  /// What is happening, in three or four words.
  final String headline;

  /// Why, or what to do about it. One sentence.
  final String detail;

  final TrackingTone tone;

  /// Label for the button, when there is something the driver can do.
  final String? action;

  bool get isActive => tone == TrackingTone.active;

  static TrackingCopy forStatus(TrackingStatus status) {
    switch (status) {
      case TrackingStatus.running:
        return const TrackingCopy(
          headline: 'Location sharing on',
          // Says both what is collected and the limit on it. A driver who reads
          // only this line should still learn that closing the app stops it.
          detail:
              'Your vehicle position is shared with your fleet operator while this app is open. '
              'It stops when you close or leave the app.',
          tone: TrackingTone.active,
        );

      case TrackingStatus.idle:
        return const TrackingCopy(
          headline: 'Location sharing off',
          detail: 'No position is being collected.',
          tone: TrackingTone.inactive,
        );

      case TrackingStatus.permissionDenied:
        return const TrackingCopy(
          headline: 'Location permission needed',
          detail:
              'Your fleet operator cannot see this vehicle until you allow location access while '
              'using the app.',
          tone: TrackingTone.attention,
          action: 'Allow access',
        );

      case TrackingStatus.permissionDeniedForever:
        return const TrackingCopy(
          headline: 'Location permission blocked',
          // The in-app prompt will not appear again, so pointing at it would
          // send the driver somewhere that does nothing.
          detail: 'Location access is turned off for FIP. You can change this in device settings.',
          tone: TrackingTone.attention,
          action: 'Open settings',
        );

      case TrackingStatus.locationServicesOff:
        return const TrackingCopy(
          headline: 'Location services are off',
          detail: 'Turn on location services on this device to share your vehicle position.',
          tone: TrackingTone.attention,
          action: 'Open settings',
        );

      case TrackingStatus.deviceNotRegistered:
        return const TrackingCopy(
          headline: 'Device not registered',
          detail:
              'This device is not linked to a vehicle. Your fleet administrator can set that up.',
          tone: TrackingTone.attention,
        );

      case TrackingStatus.deviceRevoked:
        return const TrackingCopy(
          headline: 'Device access removed',
          detail:
              'Your fleet administrator has removed this device. No location is being collected.',
          tone: TrackingTone.attention,
        );
    }
  }
}

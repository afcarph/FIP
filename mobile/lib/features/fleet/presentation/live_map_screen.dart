import 'dart:math' show Point;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:maplibre_gl/maplibre_gl.dart';

import '../../../core/config/map_config.dart';
import '../../../core/theme/fip_brand.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/status_badge.dart';
import '../data/fleet_models.dart';
import '../data/fleet_providers.dart';

/// Live map — where the fleet is now.
///
/// MapLibre with a MapTiler style, the same renderer and provider as the web
/// client. A vehicle with no position is not plotted at a default coordinate:
/// an invented pin is worse than an absent one, so unplottable vehicles are
/// counted in the summary and left off the map.
class LiveMapScreen extends ConsumerStatefulWidget {
  const LiveMapScreen({super.key});

  @override
  ConsumerState<LiveMapScreen> createState() => _LiveMapScreenState();
}

class _LiveMapScreenState extends ConsumerState<LiveMapScreen> {
  MapLibreMapController? _controller;
  int? _selectedId;

  /// Circles are keyed back to a vehicle so a tap can resolve to the record.
  final Map<String, int> _circleToVehicle = {};

  @override
  Widget build(BuildContext context) {
    final vehicles = ref.watch(fleetVehiclesProvider);

    return Scaffold(
      body: vehicles.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(FipSpace.page),
            child: FipEmptyState(
              icon: Icons.cloud_off_rounded,
              title: 'Could not load the fleet',
              message: '$error',
              action: 'Retry',
              onAction: () => ref.invalidate(fleetVehiclesProvider),
            ),
          ),
        ),
        data: (list) {
          final located = list
              .where((v) => MapConfig.hasPlottableCoordinates(v.latitude, v.longitude))
              .toList();
          final selected = located.where((v) => v.id == _selectedId).firstOrNull;
          final pulse = FleetPulseData.from(list);

          return Stack(
            children: [
              MapLibreMap(
                styleString: MapConfig.styleUrl(),
                initialCameraPosition: CameraPosition(
                  target: located.isEmpty
                      ? const LatLng(MapConfig.defaultLatitude, MapConfig.defaultLongitude)
                      : LatLng(located.first.latitude!, located.first.longitude!),
                  zoom: located.isEmpty ? MapConfig.philippinesZoom : MapConfig.defaultZoom,
                ),
                onMapCreated: (controller) => _controller = controller,
                onStyleLoadedCallback: () => _plot(located),
                onMapClick: (_, _) => setState(() => _selectedId = null),
                myLocationEnabled: false,
                compassEnabled: false,
                // The provider's attribution is a licence condition, not
                // decoration, so it is positioned rather than suppressed.
                attributionButtonMargins: const Point(8, 8),
              ),

              SafeArea(
                child: Padding(
                  padding: const EdgeInsets.all(FipSpace.md),
                  child: _MapSummary(pulse: pulse, plotted: located.length),
                ),
              ),

              if (!MapConfig.isProviderConfigured)
                const SafeArea(
                  child: Align(
                    alignment: Alignment.topCenter,
                    child: Padding(
                      padding: EdgeInsets.only(
                        top: 84,
                        left: FipSpace.page,
                        right: FipSpace.page,
                      ),
                      child: _ProviderNotice(),
                    ),
                  ),
                ),

              if (located.isEmpty)
                const SafeArea(
                  child: Align(
                    child: Padding(
                      padding: EdgeInsets.symmetric(horizontal: FipSpace.page),
                      child: FipEmptyState(
                        icon: Icons.location_searching_rounded,
                        title: 'No vehicles reporting',
                        message:
                            'Positions appear here once a registered device reports while its app is open.',
                        compact: true,
                      ),
                    ),
                  ),
                ),

              if (selected != null)
                Align(
                  alignment: Alignment.bottomCenter,
                  child: SafeArea(
                    child: Padding(
                      padding: const EdgeInsets.all(FipSpace.md),
                      child: _SelectedVehicleSheet(
                        vehicle: selected,
                        onOpen: () => context.push('/fleet/${selected.id}'),
                        onClose: () => setState(() => _selectedId = null),
                      ),
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }

  /// Draw one circle per vehicle, coloured by status.
  ///
  /// Circles rather than image symbols: they need no asset registration, scale
  /// cleanly at any zoom, and carry the status colour the rest of the app
  /// already uses.
  Future<void> _plot(List<FleetVehicle> vehicles) async {
    final controller = _controller;
    if (controller == null) return;

    // Colours are resolved from the theme before the first await. Reading
    // context after an async gap risks doing so on a disposed element.
    final colours = {
      for (final vehicle in vehicles) vehicle.id: _hex(vehicle.status.color(context)),
    };

    await controller.clearCircles();
    _circleToVehicle.clear();

    for (final vehicle in vehicles) {
      final circle = await controller.addCircle(
        CircleOptions(
          geometry: LatLng(vehicle.latitude!, vehicle.longitude!),
          circleRadius: 9,
          circleColor: colours[vehicle.id],
          circleStrokeColor: '#FFFFFF',
          circleStrokeWidth: 2.5,
          circleOpacity: 0.95,
        ),
      );

      _circleToVehicle[circle.id] = vehicle.id;
    }

    controller.onCircleTapped.add(_onCircleTapped);
  }

  void _onCircleTapped(Circle circle) {
    final id = _circleToVehicle[circle.id];
    if (id != null) setState(() => _selectedId = id);
  }

  static String _hex(Color color) =>
      '#${(color.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0')}';

  @override
  void dispose() {
    _controller?.onCircleTapped.remove(_onCircleTapped);
    super.dispose();
  }
}

/// Says the basemap is the keyless development fallback.
///
/// Without it a low-detail world map looks like a broken map rather than an
/// unconfigured one — the same mistake the web client already avoids.
class _ProviderNotice extends StatelessWidget {
  const _ProviderNotice();

  @override
  Widget build(BuildContext context) {
    return FipCard(
      padding: const EdgeInsets.symmetric(horizontal: FipSpace.md, vertical: FipSpace.sm),
      child: Row(
        children: [
          Icon(Icons.info_outline_rounded, size: 15, color: Theme.of(context).colorScheme.primary),
          const SizedBox(width: FipSpace.sm),
          Expanded(
            child: Text(
              'Development basemap. Set MAP_STYLE_URL for MapTiler tiles.',
              style: Theme.of(context).textTheme.labelSmall,
            ),
          ),
        ],
      ),
    );
  }
}

class _MapSummary extends StatelessWidget {
  const _MapSummary({required this.pulse, required this.plotted});

  final FleetPulseData pulse;
  final int plotted;

  @override
  Widget build(BuildContext context) {
    return FipCard(
      padding: const EdgeInsets.symmetric(horizontal: FipSpace.lg, vertical: FipSpace.md),
      child: Row(
        children: [
          Image.asset('assets/images/fip-mark.png', width: 24, height: 24),
          const SizedBox(width: FipSpace.md),
          _Count(label: 'Plotted', value: plotted),
          const SizedBox(width: FipSpace.lg),
          _Count(label: 'Moving', value: pulse.moving, color: FleetStatus.moving.color(context)),
          const SizedBox(width: FipSpace.lg),
          _Count(label: 'Stopped', value: pulse.stopped, color: FleetStatus.stopped.color(context)),
        ],
      ),
    );
  }
}

class _Count extends StatelessWidget {
  const _Count({required this.label, required this.value, this.color});

  final String label;
  final int value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          '$value',
          style: Theme.of(
            context,
          ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700, color: color),
        ),
        Text(label, style: Theme.of(context).textTheme.labelSmall),
      ],
    );
  }
}

class _SelectedVehicleSheet extends StatelessWidget {
  const _SelectedVehicleSheet({
    required this.vehicle,
    required this.onOpen,
    required this.onClose,
  });

  final FleetVehicle vehicle;
  final VoidCallback onOpen;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    return FipCard(
      onTap: onOpen,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  vehicle.plateNumber,
                  style: Theme.of(
                    context,
                  ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                ),
              ),
              StatusBadge.fleet(vehicle.status, context, dense: true),
              IconButton(
                onPressed: onClose,
                icon: const Icon(Icons.close_rounded, size: 18),
                visualDensity: VisualDensity.compact,
              ),
            ],
          ),
          Text('Updated ${vehicle.lastSeenLabel}', style: FipType.caption(context)),
          const SizedBox(height: FipSpace.sm),
          Row(
            children: [
              Icon(
                Icons.local_gas_station_rounded,
                size: 14,
                color: fuelColor(context, vehicle.fuelPercentage),
              ),
              const SizedBox(width: 4),
              Text(
                vehicle.fuelPercentage == null
                    ? 'No reading'
                    : '${vehicle.fuelPercentage!.toStringAsFixed(0)}%',
                style: Theme.of(context).textTheme.labelMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: fuelColor(context, vehicle.fuelPercentage),
                ),
              ),
              const Spacer(),
              Text('Open vehicle', style: Theme.of(context).textTheme.labelMedium),
              const Icon(Icons.chevron_right_rounded, size: 16),
            ],
          ),
        ],
      ),
    );
  }
}

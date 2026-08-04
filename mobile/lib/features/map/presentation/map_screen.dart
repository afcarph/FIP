import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/error_view.dart';

/// Live station prices on a map, with a ranked list beneath.
///
/// The list is not secondary: on a phone, comparing four prices is far easier
/// in a column than by tapping pins, so the map orients and the list decides.
class MapScreen extends ConsumerStatefulWidget {
  const MapScreen({super.key});

  @override
  ConsumerState<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends ConsumerState<MapScreen> {
  GoogleMapController? _controller;
  double _radiusKm = 5;
  int? _fuelTypeId;

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final location = ref.watch(locationProvider);
    final fuelTypes = ref.watch(fuelTypesProvider);
    final stations = ref.watch(
      nearbyStationsProvider((radiusKm: _radiusKm, fuelTypeId: _fuelTypeId)),
    );

    // A fixed 260 leaves a short screen with roughly 120px for the list below
    // it, which is less than the empty state needs — the map pushed it into an
    // 84px overflow. Proportional, and still 260 on anything reasonably tall.
    final mapHeight = (MediaQuery.sizeOf(context).height * 0.32).clamp(160.0, 260.0);

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            _Filters(
              fuelTypes: fuelTypes.valueOrNull ?? const [],
              selectedFuelId: _fuelTypeId,
              radiusKm: _radiusKm,
              onFuelChanged: (id) => setState(() => _fuelTypeId = id),
              onRadiusChanged: (radius) => setState(() => _radiusKm = radius),
            ),
            Expanded(
              child: location.when(
                loading: () => const Center(child: CircularProgressIndicator()),
                error:
                    (error, _) =>
                        ErrorView(error: error, onRetry: () => ref.invalidate(locationProvider)),
                data:
                    (position) => Column(
                      children: [
                        if (position.isFallback) const _FallbackNotice(),
                        SizedBox(
                          height: mapHeight,
                          child: GoogleMap(
                            initialCameraPosition: CameraPosition(
                              target: LatLng(position.latitude, position.longitude),
                              zoom: 13.5,
                            ),
                            onMapCreated: (controller) => _controller = controller,
                            myLocationEnabled: !position.isFallback,
                            myLocationButtonEnabled: true,
                            zoomControlsEnabled: false,
                            markers: _markers(stations.valueOrNull ?? const []),
                          ),
                        ),
                        Expanded(
                          child: stations.when(
                            loading: () => const Center(child: CircularProgressIndicator()),
                            error:
                                (error, _) => ErrorView(
                                  error: error,
                                  onRetry: () => ref.invalidate(nearbyStationsProvider),
                                ),
                            data:
                                (data) => _StationList(
                                  stations: _rank(data),
                                  fuelTypeId: _fuelTypeId,
                                  onRefresh: () async => ref.invalidate(nearbyStationsProvider),
                                ),
                          ),
                        ),
                      ],
                    ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Set<Marker> _markers(List<Map<String, dynamic>> stations) {
    return stations.map((station) {
      final price = _priceOf(station, _fuelTypeId);

      return Marker(
        markerId: MarkerId('${station['id']}'),
        position: LatLng(
          (station['latitude'] as num).toDouble(),
          (station['longitude'] as num).toDouble(),
        ),
        infoWindow: InfoWindow(
          title: station['name'] as String? ?? 'Station',
          snippet:
              price != null
                  ? '${Formatters.currency(price)}/L · ${Formatters.distance(station['distance_km'] as num?)}'
                  : 'No price reported',
        ),
      );
    }).toSet();
  }

  /// Cheapest first, with unpriced stations last — a station with no price
  /// cannot be compared, so it should not occupy the top of the list.
  List<Map<String, dynamic>> _rank(List<Map<String, dynamic>> stations) {
    final ranked = [...stations];

    ranked.sort((a, b) {
      final priceA = _priceOf(a, _fuelTypeId);
      final priceB = _priceOf(b, _fuelTypeId);

      if (priceA == null) return 1;
      if (priceB == null) return -1;

      return priceA.compareTo(priceB);
    });

    return ranked;
  }

  static double? _priceOf(Map<String, dynamic> station, int? fuelTypeId) {
    final prices = (station['prices'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();

    if (prices.isEmpty) return null;

    final match =
        fuelTypeId == null
            ? prices.first
            : prices.where((price) => price['fuel_type_id'] == fuelTypeId).firstOrNull;

    return (match?['price'] as num?)?.toDouble();
  }
}

class _Filters extends StatelessWidget {
  const _Filters({
    required this.fuelTypes,
    required this.selectedFuelId,
    required this.radiusKm,
    required this.onFuelChanged,
    required this.onRadiusChanged,
  });

  final List<Map<String, dynamic>> fuelTypes;
  final int? selectedFuelId;
  final double radiusKm;
  final ValueChanged<int?> onFuelChanged;
  final ValueChanged<double> onRadiusChanged;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        SizedBox(
          height: 48,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            children: [
              FilterChip(
                label: const Text('All fuels'),
                selected: selectedFuelId == null,
                onSelected: (_) => onFuelChanged(null),
              ),
              const SizedBox(width: 8),
              for (final fuel in fuelTypes.where(
                (fuel) => fuel['category'] == 'gasoline' || fuel['category'] == 'diesel',
              )) ...[
                FilterChip(
                  label: Text(fuel['name'] as String? ?? ''),
                  selected: selectedFuelId == fuel['id'],
                  onSelected: (_) => onFuelChanged(fuel['id'] as int),
                ),
                const SizedBox(width: 8),
              ],
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            children: [
              Icon(
                LucideIcons.radius,
                size: 16,
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
              const SizedBox(width: 8),
              Text('${radiusKm.toInt()} km', style: Theme.of(context).textTheme.labelMedium),
              Expanded(
                child: Slider(
                  value: radiusKm,
                  min: 1,
                  max: 25,
                  divisions: 24,
                  label: '${radiusKm.toInt()} km',
                  onChanged: onRadiusChanged,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _FallbackNotice extends StatelessWidget {
  const _FallbackNotice();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: context.fipColors.warning.withValues(alpha: 0.12),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          Icon(LucideIcons.mapPinOff, size: 15, color: context.fipColors.warning),
          const SizedBox(width: 8),
          const Expanded(
            child: Text(
              'Showing prices around Makati — enable location for results near you.',
              style: TextStyle(fontSize: 12),
            ),
          ),
        ],
      ),
    );
  }
}

class _StationList extends StatelessWidget {
  const _StationList({required this.stations, required this.fuelTypeId, required this.onRefresh});

  final List<Map<String, dynamic>> stations;
  final int? fuelTypeId;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    if (stations.isEmpty) {
      // Scrollable rather than a bare EmptyView: below the filters, the slider
      // and the map there is not always room for its full height, and a rigid
      // one overflowed. This also keeps pull-to-refresh working when there is
      // nothing to show, which is exactly when a user wants to retry.
      return RefreshIndicator(
        onRefresh: onRefresh,
        child: LayoutBuilder(
          builder:
              (context, constraints) => SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                child: ConstrainedBox(
                  constraints: BoxConstraints(minHeight: constraints.maxHeight),
                  child: const EmptyView(
                    icon: LucideIcons.mapPin,
                    title: 'No stations in range',
                    description: 'Widen the radius or clear the fuel filter.',
                  ),
                ),
              ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        itemCount: stations.length,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          final station = stations[index];
          final prices = (station['prices'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
          final match =
              fuelTypeId == null
                  ? prices.firstOrNull
                  : prices.where((price) => price['fuel_type_id'] == fuelTypeId).firstOrNull;

          return Card(
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              title: Text(
                station['name'] as String? ?? 'Station',
                style: const TextStyle(fontWeight: FontWeight.w500),
              ),
              subtitle: Text(
                [
                  (station['brand'] as Map<String, dynamic>?)?['name'] as String? ?? '',
                  Formatters.distance(station['distance_km'] as num?),
                ].where((part) => part.isNotEmpty).join(' · '),
              ),
              trailing: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    Formatters.currency(match?['price'] as num?),
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                      fontFeatures: const [FontFeature.tabularFigures()],
                    ),
                  ),
                  if (match?['is_stale'] == true)
                    Text(
                      'may be stale',
                      style: TextStyle(fontSize: 10, color: context.fipColors.warning),
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

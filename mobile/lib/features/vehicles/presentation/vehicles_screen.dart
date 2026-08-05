import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/error_view.dart';

class VehiclesScreen extends ConsumerWidget {
  const VehiclesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicles = ref.watch(vehiclesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Vehicles')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _showAddVehicleSheet(context),
        icon: const Icon(LucideIcons.plus),
        label: const Text('Add vehicle'),
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(vehiclesProvider),
          child: vehicles.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error:
                (error, _) =>
                    ErrorView(error: error, onRetry: () => ref.invalidate(vehiclesProvider)),
            data:
                (data) =>
                    data.isEmpty
                        ? EmptyView(
                          icon: LucideIcons.car,
                          title: 'No vehicles yet',
                          description: 'Add a vehicle to track its efficiency and running costs.',
                          action: FilledButton.icon(
                            onPressed: () => _showAddVehicleSheet(context),
                            icon: const Icon(LucideIcons.plus, size: 18),
                            label: const Text('Add a vehicle'),
                          ),
                        )
                        : ListView.separated(
                          padding: const EdgeInsets.all(16),
                          physics: const AlwaysScrollableScrollPhysics(),
                          itemCount: data.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 12),
                          itemBuilder: (context, index) => _VehicleCard(vehicle: data[index]),
                        ),
          ),
        ),
      ),
    );
  }
}

class _VehicleCard extends StatelessWidget {
  const _VehicleCard({required this.vehicle});

  final Map<String, dynamic> vehicle;

  @override
  Widget build(BuildContext context) {
    final efficiency = vehicle['efficiency'] as Map<String, dynamic>? ?? {};
    final documents = vehicle['documents'] as Map<String, dynamic>? ?? {};
    final deviation = efficiency['deviation_pct'] as num?;

    final registrationDays = documents['registration_expires_in_days'] as num?;
    final insuranceDays = documents['insurance_expires_in_days'] as num?;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 22,
                  backgroundColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.12),
                  child: Icon(
                    _iconFor(vehicle['vehicle_type'] as String?),
                    color: Theme.of(context).colorScheme.primary,
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        vehicle['display_name'] as String? ?? 'Vehicle',
                        style: Theme.of(
                          context,
                        ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
                      ),
                      Text(
                        [
                          vehicle['plate_number'],
                          (vehicle['fuel_type'] as Map<String, dynamic>?)?['name'],
                        ].where((part) => part != null).join(' · '),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: _Metric(
                    label: 'Odometer',
                    value: Formatters.distance(vehicle['current_odometer'] as num?),
                  ),
                ),
                Expanded(
                  child: _Metric(
                    label: 'Efficiency',
                    value: Formatters.efficiency(efficiency['avg_km_per_litre'] as num?),
                    // Only a meaningful drop is worth colouring; small
                    // variation between tanks is normal.
                    valueColor:
                        deviation != null && deviation < -5 ? context.fipColors.priceUp : null,
                  ),
                ),
                Expanded(
                  child: _Metric(
                    label: 'Range',
                    value: Formatters.distance(efficiency['estimated_range_km'] as num?),
                  ),
                ),
              ],
            ),
            if (_isExpiringSoon(registrationDays) || _isExpiringSoon(insuranceDays)) ...[
              const SizedBox(height: 14),
              const Divider(height: 1),
              const SizedBox(height: 12),
              if (_isExpiringSoon(registrationDays))
                _ExpiryChip(label: 'Registration', days: registrationDays!.toInt()),
              if (_isExpiringSoon(insuranceDays)) ...[
                const SizedBox(height: 6),
                _ExpiryChip(label: 'Insurance', days: insuranceDays!.toInt()),
              ],
            ],
          ],
        ),
      ),
    );
  }

  static bool _isExpiringSoon(num? days) => days != null && days <= 60;

  static IconData _iconFor(String? type) => switch (type) {
    'motorcycle' => LucideIcons.bike,
    'truck' || 'trailer' => LucideIcons.truck,
    'van' => LucideIcons.busFront,
    _ => LucideIcons.car,
  };
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value, this.valueColor});

  final String label;
  final String value;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: Theme.of(
            context,
          ).textTheme.labelSmall?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w600,
            color: valueColor,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
  }
}

class _ExpiryChip extends StatelessWidget {
  const _ExpiryChip({required this.label, required this.days});

  final String label;
  final int days;

  @override
  Widget build(BuildContext context) {
    final isOverdue = days < 0;
    final colour = isOverdue ? Theme.of(context).colorScheme.error : context.fipColors.warning;

    return Row(
      children: [
        Icon(isOverdue ? LucideIcons.circleAlert : LucideIcons.clock, size: 14, color: colour),
        const SizedBox(width: 8),
        Text(
          isOverdue ? '$label expired ${days.abs()} days ago' : '$label expires in $days days',
          style: TextStyle(fontSize: 12, color: colour),
        ),
      ],
    );
  }
}

/// The screen's whole purpose is unreachable without this: there was no way to
/// add a first vehicle from the app at all, and the dashboard only links here
/// once one exists.
void _showAddVehicleSheet(BuildContext context) {
  showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder:
        (context) => Padding(
          padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
          child: const _AddVehicleSheet(),
        ),
  );
}

class _AddVehicleSheet extends ConsumerStatefulWidget {
  const _AddVehicleSheet();

  @override
  ConsumerState<_AddVehicleSheet> createState() => _AddVehicleSheetState();
}

class _AddVehicleSheetState extends ConsumerState<_AddVehicleSheet> {
  final _formKey = GlobalKey<FormState>();
  final _plate = TextEditingController();
  final _nickname = TextEditingController();

  String _vehicleType = 'car';
  int? _fuelTypeId;
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _plate.dispose();
    _nickname.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      await ref
          .read(apiClientProvider)
          .post<Map<String, dynamic>>(
            '/vehicles',
            body: {
              'plate_number': _plate.text.trim(),
              if (_nickname.text.trim().isNotEmpty) 'nickname': _nickname.text.trim(),
              'vehicle_type': _vehicleType,
              'fuel_type_id': _fuelTypeId,
            },
          );

      // The dashboard hides its vehicles section when the list is empty, so it
      // has to be told as well as this screen.
      ref
        ..invalidate(vehiclesProvider)
        ..invalidate(dashboardProvider);

      if (mounted) Navigator.of(context).pop();
    } catch (error) {
      setState(() => _error = error is ApiException ? error.message : error.toString());
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final fuelTypes = ref.watch(fuelTypesProvider);

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
      child: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Add a vehicle',
              style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 16),

            TextFormField(
              controller: _plate,
              textCapitalization: TextCapitalization.characters,
              decoration: const InputDecoration(labelText: 'Plate number'),
              validator:
                  (value) =>
                      (value == null || value.trim().isEmpty) ? 'Enter the plate number.' : null,
            ),
            const SizedBox(height: 12),

            TextFormField(
              controller: _nickname,
              decoration: const InputDecoration(labelText: 'Nickname (optional)'),
            ),
            const SizedBox(height: 12),

            DropdownButtonFormField<String>(
              initialValue: _vehicleType,
              decoration: const InputDecoration(labelText: 'Type'),
              items: const [
                DropdownMenuItem(value: 'car', child: Text('Car')),
                DropdownMenuItem(value: 'suv', child: Text('SUV')),
                DropdownMenuItem(value: 'van', child: Text('Van')),
                DropdownMenuItem(value: 'motorcycle', child: Text('Motorcycle')),
                DropdownMenuItem(value: 'tricycle', child: Text('Tricycle')),
                DropdownMenuItem(value: 'jeepney', child: Text('Jeepney')),
                DropdownMenuItem(value: 'truck', child: Text('Truck')),
                DropdownMenuItem(value: 'bus', child: Text('Bus')),
                DropdownMenuItem(value: 'trailer', child: Text('Trailer')),
                DropdownMenuItem(value: 'ev', child: Text('Electric')),
              ],
              onChanged: (value) => setState(() => _vehicleType = value ?? 'car'),
            ),
            const SizedBox(height: 12),

            fuelTypes.when(
              loading: () => const LinearProgressIndicator(),
              error: (_, __) => const Text('Could not load fuel types.'),
              data:
                  (data) => DropdownButtonFormField<int>(
                    initialValue: _fuelTypeId,
                    decoration: const InputDecoration(labelText: 'Fuel type'),
                    // No silent default: the fill-up sheet displayed one it had
                    // not recorded and then refused to save.
                    hint: const Text('Choose a fuel type'),
                    items: [
                      for (final fuelType in data)
                        DropdownMenuItem(
                          value: fuelType['id'] as int,
                          child: Text(fuelType['name'] as String),
                        ),
                    ],
                    validator: (value) => value == null ? 'Choose a fuel type.' : null,
                    onChanged: (value) => setState(() => _fuelTypeId = value),
                  ),
            ),

            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ],

            const SizedBox(height: 20),
            FilledButton(
              onPressed: _submitting ? null : _submit,
              child: Text(_submitting ? 'Saving…' : 'Add vehicle'),
            ),
          ],
        ),
      ),
    );
  }
}

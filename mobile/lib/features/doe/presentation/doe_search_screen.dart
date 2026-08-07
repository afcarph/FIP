import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/doe_models.dart';
import '../data/doe_providers.dart';
import 'doe_widgets.dart';

/// Search published prices by region, area, brand and fuel type.
class DoeSearchScreen extends ConsumerStatefulWidget {
  const DoeSearchScreen({super.key});

  @override
  ConsumerState<DoeSearchScreen> createState() => _DoeSearchScreenState();
}

class _DoeSearchScreenState extends ConsumerState<DoeSearchScreen> {
  final _areaController = TextEditingController();
  Timer? _debounce;

  String? _region;
  String? _brand;
  String? _fuelCode;
  String _area = '';

  @override
  void initState() {
    super.initState();
    // Seeded from Home, so choosing a region there and tapping Search does not
    // drop the choice.
    _region = ref.read(selectedRegionProvider);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _areaController.dispose();
    super.dispose();
  }

  /// Debounced so a typed area does not fire a request per keystroke.
  void _onAreaChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      if (mounted) setState(() => _area = value);
    });
  }

  @override
  Widget build(BuildContext context) {
    final health = ref.watch(doeHealthProvider);
    final brands = ref.watch(doeBrandsProvider);

    final query = DoeQuery(
      region: _region,
      area: _area,
      brand: _brand,
      fuelCode: _fuelCode,
      perPage: 60,
    );
    final results = ref.watch(doeSearchProvider(query));

    final regions = health.asData?.value.regions ?? const <String>[];

    return Scaffold(
      appBar: AppBar(title: const Text('Search')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Column(
              children: [
                TextField(
                  controller: _areaController,
                  onChanged: _onAreaChanged,
                  decoration: const InputDecoration(
                    labelText: 'Area',
                    hintText: 'e.g. Quezon City',
                    isDense: true,
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: _Dropdown(
                        label: 'Region',
                        value: _region,
                        options: regions,
                        onChanged: (value) => setState(() => _region = value),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: _Dropdown(
                        label: 'Brand',
                        value: _brand,
                        options: brands.asData?.value ?? const [],
                        onChanged: (value) => setState(() => _brand = value),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                _Dropdown(
                  label: 'Fuel type',
                  value: _fuelCode,
                  options: fuelTypeOptions.map((fuel) => fuel.code).toList(),
                  labelFor: (code) =>
                      fuelTypeOptions.firstWhere((fuel) => fuel.code == code).label,
                  onChanged: (value) => setState(() => _fuelCode = value),
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          Expanded(
            child: results.when(
              loading: () => const DoeLoading(label: 'Searching'),
              error: (error, _) => DoeError(
                error: error,
                onRetry: () => ref.invalidate(doeSearchProvider(query)),
              ),
              data: (rows) => rows.isEmpty
                  ? const DoeEmpty(
                      title: 'No prices match those filters',
                      hint: 'Try widening the region, or clearing the area.',
                    )
                  : ListView.separated(
                      itemCount: rows.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (context, index) => DoePriceTile(price: rows[index]),
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Dropdown extends StatelessWidget {
  const _Dropdown({
    required this.label,
    required this.value,
    required this.options,
    required this.onChanged,
    this.labelFor,
  });

  final String label;
  final String? value;
  final List<String> options;
  final ValueChanged<String?> onChanged;
  final String Function(String)? labelFor;

  @override
  Widget build(BuildContext context) => DropdownButtonFormField<String?>(
    initialValue: value,
    isExpanded: true,
    decoration: InputDecoration(labelText: label, isDense: true, border: const OutlineInputBorder()),
    items: [
      DropdownMenuItem<String?>(value: null, child: Text('All ${label.toLowerCase()}s')),
      for (final option in options)
        DropdownMenuItem<String?>(
          value: option,
          child: Text(labelFor?.call(option) ?? option, overflow: TextOverflow.ellipsis),
        ),
    ],
    onChanged: onChanged,
  );
}

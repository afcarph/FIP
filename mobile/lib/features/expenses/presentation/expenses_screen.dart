import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/utils/formatters.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/error_view.dart';
import '../../../shared/widgets/stat_tile.dart';

final expenseSummaryProvider = FutureProvider<Map<String, dynamic>>((ref) async {
  return ref.watch(apiClientProvider).get<Map<String, dynamic>>('/expenses/summary');
});

final expenseListProvider = FutureProvider<List<Map<String, dynamic>>>((ref) async {
  final data = await ref
      .watch(apiClientProvider)
      .get<List<dynamic>>('/expenses', query: {'per_page': 30});

  return data.cast<Map<String, dynamic>>();
});

class ExpensesScreen extends ConsumerWidget {
  const ExpensesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summary = ref.watch(expenseSummaryProvider);
    final purchases = ref.watch(expenseListProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Fuel expenses')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _showLogSheet(context, ref),
        icon: const Icon(LucideIcons.plus),
        label: const Text('Log fill-up'),
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async {
            ref
              ..invalidate(expenseSummaryProvider)
              ..invalidate(expenseListProvider);
          },
          child: CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              SliverPadding(
                padding: const EdgeInsets.all(16),
                sliver: SliverToBoxAdapter(
                  child: summary.when(
                    loading: () => const SizedBox(height: 160),
                    error:
                        (error, _) => ErrorView(
                          error: error,
                          onRetry: () => ref.invalidate(expenseSummaryProvider),
                        ),
                    data: (data) => _SummaryGrid(data: data),
                  ),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                sliver: SliverToBoxAdapter(
                  child: Text(
                    'Recent fill-ups',
                    style: Theme.of(
                      context,
                    ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
                  ),
                ),
              ),
              purchases.when(
                loading:
                    () => const SliverToBoxAdapter(
                      child: Center(
                        child: Padding(
                          padding: EdgeInsets.all(32),
                          child: CircularProgressIndicator(),
                        ),
                      ),
                    ),
                error:
                    (error, _) => SliverToBoxAdapter(
                      child: ErrorView(
                        error: error,
                        onRetry: () => ref.invalidate(expenseListProvider),
                      ),
                    ),
                data:
                    (data) =>
                        data.isEmpty
                            ? const SliverToBoxAdapter(
                              child: EmptyView(
                                icon: LucideIcons.receipt,
                                title: 'No fill-ups logged',
                                description:
                                    'Log one and we will work out your cost per kilometre.',
                              ),
                            )
                            : SliverPadding(
                              padding: const EdgeInsets.fromLTRB(16, 0, 16, 88),
                              sliver: SliverList.separated(
                                itemCount: data.length,
                                separatorBuilder: (_, __) => const SizedBox(height: 8),
                                itemBuilder:
                                    (context, index) => _PurchaseRow(purchase: data[index]),
                              ),
                            ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showLogSheet(BuildContext context, WidgetRef ref) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder:
          (context) => Padding(
            // Lift the sheet above the keyboard so the submit button stays visible.
            padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
            child: const _LogFillUpSheet(),
          ),
    );
  }
}

class _SummaryGrid extends StatelessWidget {
  const _SummaryGrid({required this.data});

  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final summary = data['summary'] as Map<String, dynamic>? ?? {};
    final savings = data['savings'] as Map<String, dynamic>? ?? {};

    return GridView.count(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      crossAxisCount: 2,
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      // 1.25, not 1.55: at the tighter ratio a tile with a hint is
      // shorter than its own content, so every such tile scaled its
      // text down to avoid overflowing. StatTile still guards against
      // overflow for large text scales; this stops it triggering at the
      // default one.
      childAspectRatio: 1.25,
      children: [
        StatTile(
          label: 'Total spend',
          value: Formatters.compactCurrency(summary['total_cost'] as num?),
          icon: LucideIcons.wallet,
          hint: '${summary['fill_ups'] ?? 0} fill-ups',
        ),
        StatTile(
          label: 'Litres',
          value: Formatters.litres(summary['total_litres'] as num?),
          icon: LucideIcons.droplet,
          accent: StatAccent.warning,
        ),
        StatTile(
          label: 'Cost per km',
          value:
              summary['avg_cost_per_km'] != null
                  ? '${Formatters.currency(summary['avg_cost_per_km'] as num?)}/km'
                  : '—',
          icon: LucideIcons.route,
          accent: StatAccent.success,
        ),
        StatTile(
          label: 'Missed savings',
          value: Formatters.compactCurrency(savings['potential_savings'] as num?),
          icon: LucideIcons.piggyBank,
          accent: StatAccent.danger,
        ),
      ],
    );
  }
}

class _PurchaseRow extends StatelessWidget {
  const _PurchaseRow({required this.purchase});

  final Map<String, dynamic> purchase;

  @override
  Widget build(BuildContext context) {
    final isFlagged = purchase['is_flagged'] == true;
    final station = purchase['station'] as Map<String, dynamic>?;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        leading: CircleAvatar(
          backgroundColor:
              isFlagged
                  ? Theme.of(context).colorScheme.errorContainer
                  : Theme.of(context).colorScheme.primary.withValues(alpha: 0.12),
          child: Icon(
            isFlagged ? LucideIcons.triangleAlert : LucideIcons.fuel,
            size: 18,
            color:
                isFlagged
                    ? Theme.of(context).colorScheme.error
                    : Theme.of(context).colorScheme.primary,
          ),
        ),
        title: Text(
          station?['name'] as String? ?? 'Fill-up',
          style: const TextStyle(fontWeight: FontWeight.w500),
        ),
        subtitle: Text(
          [
            Formatters.relative(DateTime.tryParse(purchase['purchased_at'] as String? ?? '')),
            Formatters.litres(purchase['litres'] as num?),
            if (purchase['km_per_litre'] != null)
              Formatters.efficiency(purchase['km_per_litre'] as num?),
          ].join(' · '),
        ),
        trailing: Text(
          Formatters.currency(purchase['total_cost'] as num?),
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w600,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      ),
    );
  }
}

/// Quick-entry form. Deliberately short — four fields is the difference
/// between a habit and an abandoned feature.
class _LogFillUpSheet extends ConsumerStatefulWidget {
  const _LogFillUpSheet();

  @override
  ConsumerState<_LogFillUpSheet> createState() => _LogFillUpSheetState();
}

class _LogFillUpSheetState extends ConsumerState<_LogFillUpSheet> {
  final _formKey = GlobalKey<FormState>();
  final _litres = TextEditingController();
  final _price = TextEditingController();
  final _odometer = TextEditingController();

  int? _vehicleId;
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _litres.dispose();
    _price.dispose();
    _odometer.dispose();
    super.dispose();
  }

  double get _total {
    final litres = double.tryParse(_litres.text) ?? 0;
    final price = double.tryParse(_price.text) ?? 0;

    return litres * price;
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false) || _vehicleId == null) {
      setState(() => _error = _vehicleId == null ? 'Choose a vehicle.' : null);
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      await ref
          .read(apiClientProvider)
          .post<Map<String, dynamic>>(
            '/expenses',
            body: {
              'vehicle_id': _vehicleId,
              'litres': double.parse(_litres.text),
              'price_per_litre': double.parse(_price.text),
              'total_cost': _total,
              if (_odometer.text.isNotEmpty) 'odometer': double.parse(_odometer.text),
              'is_full_tank': true,
              'purchased_at': DateTime.now().toIso8601String(),
            },
          );

      ref
        ..invalidate(expenseSummaryProvider)
        ..invalidate(expenseListProvider)
        ..invalidate(dashboardProvider);

      if (mounted) Navigator.of(context).pop();
    } catch (error) {
      setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final vehicles = ref.watch(vehiclesProvider);

    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
      child: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Log a fill-up',
              style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 16),

            vehicles.when(
              loading: () => const LinearProgressIndicator(),
              error: (_, __) => const Text('Could not load your vehicles.'),
              data:
                  (data) => DropdownButtonFormField<int>(
                    initialValue: _vehicleId ?? (data.length == 1 ? data.first['id'] as int : null),
                    decoration: const InputDecoration(labelText: 'Vehicle'),
                    items: [
                      for (final vehicle in data)
                        DropdownMenuItem(
                          value: vehicle['id'] as int,
                          child: Text(
                            vehicle['display_name'] as String? ?? vehicle['plate_number'] as String,
                          ),
                        ),
                    ],
                    onChanged: (value) => setState(() => _vehicleId = value),
                  ),
            ),

            const SizedBox(height: 12),

            Row(
              children: [
                Expanded(
                  child: TextFormField(
                    controller: _litres,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(labelText: 'Litres', suffixText: 'L'),
                    onChanged: (_) => setState(() {}),
                    validator: (value) {
                      final parsed = double.tryParse(value ?? '');
                      if (parsed == null || parsed <= 0) return 'Enter the litres.';
                      return null;
                    },
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextFormField(
                    controller: _price,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(labelText: 'Price', prefixText: '₱'),
                    onChanged: (_) => setState(() {}),
                    validator: (value) {
                      final parsed = double.tryParse(value ?? '');
                      if (parsed == null || parsed <= 0) return 'Enter the price.';
                      return null;
                    },
                  ),
                ),
              ],
            ),

            const SizedBox(height: 12),

            TextFormField(
              controller: _odometer,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'Odometer (optional)',
                suffixText: 'km',
                helperText: 'Needed to work out km/L',
              ),
            ),

            const SizedBox(height: 16),

            // Live total: the arithmetic check happens before submit, not
            // after a server round trip.
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('Total'),
                  Text(
                    Formatters.currency(_total),
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                      fontFeatures: const [FontFeature.tabularFigures()],
                    ),
                  ),
                ],
              ),
            ),

            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 13),
              ),
            ],

            const SizedBox(height: 16),

            FilledButton(
              onPressed: _submitting ? null : _submit,
              child:
                  _submitting
                      ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                      : const Text('Save'),
            ),
          ],
        ),
      ),
    );
  }
}

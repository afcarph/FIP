import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:fl_chart/fl_chart.dart';

import '../data/doe_models.dart';
import '../data/doe_providers.dart';
import 'doe_widgets.dart';

/// Every imported report, with a trend for one grade above it.
class DoeHistoryScreen extends ConsumerStatefulWidget {
  const DoeHistoryScreen({super.key});

  @override
  ConsumerState<DoeHistoryScreen> createState() => _DoeHistoryScreenState();
}

class _DoeHistoryScreenState extends ConsumerState<DoeHistoryScreen> {
  String _fuelCode = 'diesel';

  @override
  Widget build(BuildContext context) {
    final reports = ref.watch(doeReportsProvider);
    final trend = ref.watch(doeTrendProvider(_fuelCode));

    return Scaffold(
      appBar: AppBar(title: const Text('History')),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(doeReportsProvider);
          ref.invalidate(doeTrendProvider);
        },
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Text('Trend', style: Theme.of(context).textTheme.titleMedium),
                        const Spacer(),
                        DropdownButton<String>(
                          value: _fuelCode,
                          underline: const SizedBox.shrink(),
                          items: [
                            for (final fuel in const [
                              (code: 'diesel', label: 'Diesel'),
                              (code: 'gasoline_ron91', label: 'RON 91'),
                              (code: 'gasoline_ron95', label: 'RON 95'),
                            ])
                              DropdownMenuItem(value: fuel.code, child: Text(fuel.label)),
                          ],
                          onChanged: (value) => setState(() => _fuelCode = value ?? 'diesel'),
                        ),
                      ],
                    ),
                    SizedBox(
                      height: 180,
                      child: trend.when(
                        loading: () => const DoeLoading(),
                        error: (error, _) => DoeError(
                          error: error,
                          onRetry: () => ref.invalidate(doeTrendProvider(_fuelCode)),
                        ),
                        data: (points) => points.isEmpty
                            ? const DoeEmpty(title: 'No published weeks yet')
                            : LineChart(
                                LineChartData(
                                  gridData: const FlGridData(show: true, drawVerticalLine: false),
                                  titlesData: FlTitlesData(
                                    topTitles: const AxisTitles(),
                                    rightTitles: const AxisTitles(),
                                    bottomTitles: AxisTitles(
                                      sideTitles: SideTitles(
                                        showTitles: true,
                                        reservedSize: 22,
                                        // Only the ends and the middle. Twelve
                                        // week labels across a phone would
                                        // overlap into an unreadable smear.
                                        interval: 1,
                                        getTitlesWidget: (value, meta) =>
                                            _weekLabel(context, points, value),
                                      ),
                                    ),
                                  ),
                                  borderData: FlBorderData(show: false),
                                  lineBarsData: [
                                    // Midpoint solid, bounds dashed. The DOE
                                    // publishes a range; one line would imply a
                                    // precision the source does not have.
                                    _series(points.map((p) => p.midpoint).toList(), width: 3),
                                    _series(
                                      points.map((p) => p.lowest).toList(),
                                      width: 1,
                                      dashed: true,
                                    ),
                                    _series(
                                      points.map((p) => p.highest).toList(),
                                      width: 1,
                                      dashed: true,
                                    ),
                                  ],
                                ),
                              ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Solid line is the midpoint of the published range; dashed lines bound it.',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            Text('Imported reports', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            reports.when(
              loading: () => const DoeLoading(),
              error: (error, _) =>
                  DoeError(error: error, onRetry: () => ref.invalidate(doeReportsProvider)),
              data: (rows) => rows.isEmpty
                  ? const DoeEmpty(
                      title: 'No reports imported yet',
                      hint: 'The ingest runs at 06:00 Asia/Manila.',
                    )
                  : Card(
                      clipBehavior: Clip.antiAlias,
                      child: Column(
                        children: [
                          for (final report in rows)
                            ListTile(
                              dense: true,
                              title: Text(report.coverageLabel),
                              subtitle: Text(
                                '${report.region} · ${report.areasCount} areas · '
                                '${report.rowsCount} rows',
                              ),
                              trailing: report.quality == null
                                  ? null
                                  : Text(report.quality!.toStringAsFixed(2)),
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

  /// The coverage week under a point, at the two ends and the middle.
  Widget _weekLabel(BuildContext context, List<TrendPoint> points, double value) {
    final index = value.round();

    if (index < 0 || index >= points.length) return const SizedBox.shrink();
    if (index != 0 && index != points.length - 1 && index != points.length ~/ 2) {
      return const SizedBox.shrink();
    }

    final parsed = DateTime.tryParse(points[index].coverageStart);

    if (parsed == null) return const SizedBox.shrink();

    // Labels are centred on their point, so the first and last would each hang
    // half their width off the chart and be clipped by the card. Pull them
    // back inside.
    final nudge = switch (index) {
      0 => 0.4,
      _ when index == points.length - 1 => -0.4,
      _ => 0.0,
    };

    return FractionalTranslation(
      translation: Offset(nudge, 0),
      child: Padding(
        padding: const EdgeInsets.only(top: 4),
        child: Text(
          '${parsed.day} ${_months[parsed.month - 1]}',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ),
    );
  }

  static const _months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];

  LineChartBarData _series(List<double> values, {required double width, bool dashed = false}) =>
      LineChartBarData(
        spots: [
          for (var index = 0; index < values.length; index++)
            FlSpot(index.toDouble(), values[index]),
        ],
        // Straight segments: the DOE publishes one figure a week, and a curve
        // would draw prices between them that were never measured.
        isCurved: false,
        barWidth: width,
        dotData: const FlDotData(show: false),
        dashArray: dashed ? [4, 3] : null,
      );
}

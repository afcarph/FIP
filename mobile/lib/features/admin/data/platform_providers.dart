import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../shared/providers/app_providers.dart';

/// Platform health, read from `/admin/system`.
///
/// Every field is nullable because the endpoint reports what it can measure
/// and omits what it cannot. A missing check is shown as an em dash rather
/// than a zero: "not measured" and "measured as nothing" are different
/// statements, and only one of them is reassuring.
class PlatformHealth {
  const PlatformHealth({
    required this.status,
    this.databaseLatencyMs,
    this.schedulerStatus,
    this.schedulerLastRun,
    this.diskUsedPercent,
  });

  final String status;
  final double? databaseLatencyMs;
  final String? schedulerStatus;
  final String? schedulerLastRun;
  final double? diskUsedPercent;

  bool get isHealthy => status.toLowerCase() == 'ok';

  static double? _num(dynamic v) => v == null ? null : (v as num).toDouble();

  factory PlatformHealth.fromJson(Map<String, dynamic> json) {
    final checks = json['checks'] as Map<String, dynamic>? ?? const {};
    final database = checks['database'] as Map<String, dynamic>?;
    final scheduler = checks['scheduler'] as Map<String, dynamic>?;
    final disk = checks['disk'] as Map<String, dynamic>?;

    return PlatformHealth(
      status: (json['status'] as String?) ?? 'unknown',
      databaseLatencyMs: _num(database?['latency_ms']),
      schedulerStatus: scheduler?['status'] as String?,
      schedulerLastRun: scheduler?['last_run_at'] as String?,
      diskUsedPercent: _num(disk?['used_percent']),
    );
  }
}

/// Requires `audit.view` or a platform admin role; the server decides.
final systemHealthProvider = FutureProvider<PlatformHealth>((ref) async {
  final auth = ref.watch(authProvider);
  if (!auth.isAuthenticated) return const PlatformHealth(status: 'unknown');

  final api = ref.read(apiClientProvider);
  final response = await api.get<dynamic>('/admin/system');
  final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

  return PlatformHealth.fromJson(data as Map<String, dynamic>);
});

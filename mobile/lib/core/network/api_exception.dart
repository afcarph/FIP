import 'package:dio/dio.dart';

/// A failed API call, carrying the platform's machine-readable error code.
///
/// The code matters: the UI reacts differently to `report_too_far` (show the
/// distance, offer to re-locate) than to `validation_failed` (highlight the
/// fields), and both differently again from a network drop.
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.message,
    this.statusCode,
    this.fieldErrors = const {},
    this.requestId,
  });

  final String code;
  final String message;
  final int? statusCode;
  final Map<String, List<String>> fieldErrors;
  final String? requestId;

  factory ApiException.fromDio(DioException error) {
    // Connectivity problems never reach the server, so there is no envelope
    // to parse — and telling the user "server error" would be wrong.
    if (error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.receiveTimeout ||
        error.type == DioExceptionType.sendTimeout) {
      return const ApiException(
        code: 'timeout',
        message: 'The request timed out. Check your connection and try again.',
      );
    }

    if (error.type == DioExceptionType.connectionError) {
      return const ApiException(
        code: 'offline',
        message: 'You appear to be offline. Some data may be out of date.',
      );
    }

    final body = error.response?.data;

    if (body is Map<String, dynamic>) {
      final envelope = body['error'] as Map<String, dynamic>?;
      final details = envelope?['details'] as Map<String, dynamic>?;

      return ApiException(
        code: envelope?['code'] as String? ?? 'request_failed',
        message: envelope?['message'] as String? ?? 'Something went wrong.',
        statusCode: error.response?.statusCode,
        fieldErrors:
            details?.map((key, value) => MapEntry(key, (value as List<dynamic>).cast<String>())) ??
            const {},
        requestId: (body['meta'] as Map<String, dynamic>?)?['request_id'] as String?,
      );
    }

    return ApiException(
      code: 'request_failed',
      message: 'Request failed with status ${error.response?.statusCode ?? 'unknown'}.',
      statusCode: error.response?.statusCode,
    );
  }

  bool get isOffline => code == 'offline' || code == 'timeout';
  bool get isAuth => statusCode == 401 || code == 'unauthenticated';
  bool get isValidation => code == 'validation_failed';

  /// First message per field, ready to hand to form validators.
  Map<String, String> get firstFieldErrors =>
      fieldErrors.map((field, messages) => MapEntry(field, messages.first));

  @override
  String toString() => 'ApiException($code): $message';
}

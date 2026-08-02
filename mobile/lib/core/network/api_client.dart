import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:pretty_dio_logger/pretty_dio_logger.dart';

import '../config/app_config.dart';
import 'api_exception.dart';

/// HTTP client for the FIP API.
///
/// The interceptor handles two things that are easy to get wrong on mobile:
///
/// * **Serialised refresh.** A dashboard screen fires several requests at
///   once. If each one refreshed the token independently, the first would
///   succeed and the rest would present an already-rotated token. A single
///   shared [Completer] means one refresh, and everyone waits for it.
///
/// * **Queued retries.** Requests that hit a 401 during a refresh are held
///   and replayed with the new token rather than failing the screen.
class ApiClient {
  ApiClient({FlutterSecureStorage? storage, Dio? dio})
      : _storage = storage ?? const FlutterSecureStorage(),
        _dio = dio ?? Dio() {
    _configure();
  }

  static const String _tokenKey = 'fip.access_token';
  static const String _deviceKey = 'fip.device_uuid';

  final Dio _dio;
  final FlutterSecureStorage _storage;

  /// Non-null while a refresh is in flight; every concurrent 401 awaits it.
  Completer<bool>? _refreshCompleter;

  Dio get raw => _dio;

  void _configure() {
    _dio.options = BaseOptions(
      baseUrl: AppConfig.apiBaseUrl,
      connectTimeout: AppConfig.connectTimeout,
      receiveTimeout: AppConfig.receiveTimeout,
      headers: {'Accept': 'application/json'},
      // Handle every status ourselves so error envelopes are parsed uniformly.
      validateStatus: (status) => status != null && status < 500,
    );

    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _storage.read(key: _tokenKey);

          if (token != null && options.extra['skipAuth'] != true) {
            options.headers['Authorization'] = 'Bearer $token';
          }

          options.headers['X-Device-Id'] = await deviceUuid();

          handler.next(options);
        },
        onResponse: (response, handler) {
          final status = response.statusCode ?? 0;

          if (status >= 400) {
            handler.reject(
              DioException(
                requestOptions: response.requestOptions,
                response: response,
                type: DioExceptionType.badResponse,
              ),
            );
            return;
          }

          handler.next(response);
        },
        onError: (error, handler) async {
          final isUnauthorised = error.response?.statusCode == 401;
          final alreadyRetried = error.requestOptions.extra['retried'] == true;
          final skipAuth = error.requestOptions.extra['skipAuth'] == true;

          if (!isUnauthorised || alreadyRetried || skipAuth) {
            handler.next(error);
            return;
          }

          final refreshed = await _refreshToken();

          if (!refreshed) {
            await clearSession();
            handler.next(error);
            return;
          }

          try {
            final options = error.requestOptions..extra['retried'] = true;
            options.headers['Authorization'] = 'Bearer ${await _storage.read(key: _tokenKey)}';

            final response = await _dio.fetch<dynamic>(options);
            handler.resolve(response);
          } on DioException catch (retryError) {
            handler.next(retryError);
          }
        },
      ),
    );

    if (!AppConfig.isProduction) {
      _dio.interceptors.add(
        PrettyDioLogger(requestBody: true, responseBody: false, compact: true),
      );
    }
  }

  Future<bool> _refreshToken() async {
    // Someone else is already refreshing — wait for their result.
    if (_refreshCompleter != null) {
      return _refreshCompleter!.future;
    }

    final completer = Completer<bool>();
    _refreshCompleter = completer;

    try {
      final token = await _storage.read(key: _tokenKey);

      if (token == null) {
        completer.complete(false);
        return false;
      }

      final response = await Dio(BaseOptions(baseUrl: AppConfig.apiBaseUrl)).post<dynamic>(
        '/auth/refresh',
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );

      final newToken = (response.data as Map<String, dynamic>?)?['data']?['access_token'] as String?;

      if (newToken != null) {
        await _storage.write(key: _tokenKey, value: newToken);
        completer.complete(true);
        return true;
      }

      completer.complete(false);
      return false;
    } on DioException {
      completer.complete(false);
      return false;
    } finally {
      _refreshCompleter = null;
    }
  }

  // ------------------------------------------------------------ session ---

  Future<void> saveToken(String token) => _storage.write(key: _tokenKey, value: token);

  Future<String?> readToken() => _storage.read(key: _tokenKey);

  Future<void> clearSession() => _storage.delete(key: _tokenKey);

  /// Stable per-install identifier used for device management and biometrics.
  Future<String> deviceUuid() async {
    var uuid = await _storage.read(key: _deviceKey);

    if (uuid == null) {
      uuid = DateTime.now().microsecondsSinceEpoch.toRadixString(36) +
          (100000 + DateTime.now().millisecond * 7).toRadixString(36);
      await _storage.write(key: _deviceKey, value: uuid);
    }

    return uuid;
  }

  // ------------------------------------------------------------ requests ---

  Future<T> get<T>(
    String path, {
    Map<String, dynamic>? query,
    bool skipAuth = false,
  }) async {
    return _unwrap<T>(
      () => _dio.get<dynamic>(
        path,
        queryParameters: _pruneNulls(query),
        options: Options(extra: {'skipAuth': skipAuth}),
      ),
    );
  }

  Future<T> post<T>(String path, {Object? body, bool skipAuth = false}) async {
    return _unwrap<T>(
      () => _dio.post<dynamic>(path, data: body, options: Options(extra: {'skipAuth': skipAuth})),
    );
  }

  Future<T> put<T>(String path, {Object? body}) async {
    return _unwrap<T>(() => _dio.put<dynamic>(path, data: body));
  }

  Future<T> patch<T>(String path, {Object? body}) async {
    return _unwrap<T>(() => _dio.patch<dynamic>(path, data: body));
  }

  Future<void> delete(String path) async {
    await _unwrap<dynamic>(() => _dio.delete<dynamic>(path));
  }

  /// Multipart upload for the price-board scanner.
  Future<T> upload<T>(String path, FormData formData) async {
    return _unwrap<T>(
      () => _dio.post<dynamic>(
        path,
        data: formData,
        options: Options(sendTimeout: AppConfig.uploadTimeout, receiveTimeout: AppConfig.uploadTimeout),
      ),
    );
  }

  Future<T> _unwrap<T>(Future<Response<dynamic>> Function() send) async {
    try {
      final response = await send();
      final body = response.data;

      if (body is Map<String, dynamic>) {
        return body['data'] as T;
      }

      return body as T;
    } on DioException catch (error) {
      throw ApiException.fromDio(error);
    }
  }

  static Map<String, dynamic>? _pruneNulls(Map<String, dynamic>? query) {
    if (query == null) return null;

    // Dio serialises nulls as empty parameters, which the API then rejects.
    return Map<String, dynamic>.fromEntries(
      query.entries.where((entry) => entry.value != null),
    );
  }
}

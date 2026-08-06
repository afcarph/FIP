import 'package:dio/dio.dart';
import 'package:fip_mobile/core/network/api_exception.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final options = RequestOptions(path: '/test');

  test('parses the platform error envelope', () {
    final exception = ApiException.fromDio(
      DioException(
        requestOptions: options,
        response: Response<dynamic>(
          requestOptions: options,
          statusCode: 422,
          data: {
            'success': false,
            'error': {
              'code': 'validation_failed',
              'message': 'The given data was invalid.',
              'details': {
                'email': ['The email field is required.'],
              },
            },
            'meta': {'request_id': 'abc-123'},
          },
        ),
        type: DioExceptionType.badResponse,
      ),
    );

    expect(exception.code, 'validation_failed');
    expect(exception.isValidation, isTrue);
    expect(exception.firstFieldErrors['email'], 'The email field is required.');
    expect(exception.requestId, 'abc-123');
  });

  test('distinguishes a connection failure from a server error', () {
    final unreachable = ApiException.fromDio(
      DioException(requestOptions: options, type: DioExceptionType.connectionError),
    );

    // Telling a user on a weak signal that the server broke sends them hunting
    // for the wrong problem — but so does telling a user with full signal that
    // they are offline, which is what a build pointed at an unreachable host
    // produced. The message names both causes rather than picking one.
    expect(unreachable.isOffline, isTrue);
    expect(unreachable.code, 'unreachable');
    expect(unreachable.message, contains('Could not reach the server'));
    expect(unreachable.message, contains('wrong address'));
  });

  test('reports a timeout distinctly', () {
    final timeout = ApiException.fromDio(
      DioException(requestOptions: options, type: DioExceptionType.receiveTimeout),
    );

    expect(timeout.code, 'timeout');
    expect(timeout.isOffline, isTrue);
  });

  test('flags an authentication failure', () {
    final unauthorised = ApiException.fromDio(
      DioException(
        requestOptions: options,
        response: Response<dynamic>(
          requestOptions: options,
          statusCode: 401,
          data: {
            'error': {'code': 'unauthenticated', 'message': 'Authentication is required.'},
          },
        ),
        type: DioExceptionType.badResponse,
      ),
    );

    expect(unauthorised.isAuth, isTrue);
  });

  test('degrades gracefully when the body is not the expected envelope', () {
    final malformed = ApiException.fromDio(
      DioException(
        requestOptions: options,
        response: Response<dynamic>(
          requestOptions: options,
          statusCode: 500,
          data: '<html>oops</html>',
        ),
        type: DioExceptionType.badResponse,
      ),
    );

    expect(malformed.code, 'request_failed');
    expect(malformed.statusCode, 500);
  });
}

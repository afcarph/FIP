import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:fip_mobile/core/network/api_client.dart';

/// Secure storage that behaves the way the iOS keychain does under concurrent
/// writes: the second write to a key that another write is still committing
/// throws. Reads and writes are also given a delay, because without one the
/// race the test is about cannot happen.
class _RacyStorage extends FlutterSecureStorage {
  const _RacyStorage(this._values, this._writing);

  final Map<String, String> _values;
  final Set<String> _writing;

  @override
  Future<String?> read({
    required String key,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    await Future<void>.delayed(const Duration(milliseconds: 5));

    return _values[key];
  }

  @override
  Future<void> write({
    required String key,
    required String? value,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    if (_writing.contains(key)) {
      throw StateError('concurrent keychain write for $key');
    }

    _writing.add(key);
    await Future<void>.delayed(const Duration(milliseconds: 5));
    _values[key] = value!;
    _writing.remove(key);
  }
}

class _CountingStorage extends _RacyStorage {
  _CountingStorage() : super({}, {});

  int writes = 0;

  @override
  Future<void> write({
    required String key,
    required String? value,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    writes++;

    return super.write(key: key, value: value);
  }
}

void main() {
  group('deviceUuid', () {
    test('concurrent callers share one write on a fresh install', () async {
      // Every request stamps X-Device-Id, so the first screen of a first
      // launch calls this several times at once. Before it was serialised,
      // each call found nothing stored and raced to write; the loser threw,
      // Dio surfaced it as an unknown error, and the screen reported the API
      // as broken on the one launch where it was not.
      final storage = _CountingStorage();
      final client = ApiClient(storage: storage);

      final results = await Future.wait([
        client.deviceUuid(),
        client.deviceUuid(),
        client.deviceUuid(),
        client.deviceUuid(),
      ]);

      expect(storage.writes, 1);
      expect(results.toSet(), hasLength(1), reason: 'all callers must see the same identifier');
      expect(results.first, isNotEmpty);
    });

    test('an existing identifier is reused rather than regenerated', () async {
      final storage = _RacyStorage({'fip.device_uuid': 'stored-uuid'}, {});
      final client = ApiClient(storage: storage);

      expect(await client.deviceUuid(), 'stored-uuid');
      expect(await client.deviceUuid(), 'stored-uuid');
    });
  });
}

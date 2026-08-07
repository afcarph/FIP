import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/config/app_config.dart';
import '../../../shared/providers/app_providers.dart';

/// Settings a UAT tester can change without a rebuild.
///
/// `AppConfig.apiBaseUrl` is a compile-time `String.fromEnvironment`, which is
/// right for a shipped build and wrong for acceptance testing: repointing at a
/// different staging box would otherwise mean recompiling and reinstalling the
/// APK. The override below is stored on the device and applied to the live Dio
/// instance, so the compile-time value remains the default and nothing changes
/// for a normal build.

const _apiUrlKey = 'uat_api_base_url';

/// The stored override, or the compile-time default. Called from `main()`
/// before the first widget builds so the very first request already goes to
/// the right host.
Future<String> resolveApiBaseUrl() async {
  final prefs = await SharedPreferences.getInstance();

  return prefs.getString(_apiUrlKey) ?? AppConfig.apiBaseUrl;
}
const _themeKey = 'uat_theme_mode';

class UatSettings {
  const UatSettings({required this.apiBaseUrl, required this.themeMode, this.isOverridden = false});

  final String apiBaseUrl;
  final ThemeMode themeMode;

  /// Whether the URL came from the device rather than the build.
  final bool isOverridden;

  UatSettings copyWith({String? apiBaseUrl, ThemeMode? themeMode, bool? isOverridden}) =>
      UatSettings(
        apiBaseUrl: apiBaseUrl ?? this.apiBaseUrl,
        themeMode: themeMode ?? this.themeMode,
        isOverridden: isOverridden ?? this.isOverridden,
      );
}

class UatSettingsNotifier extends StateNotifier<UatSettings> {
  UatSettingsNotifier(this._ref)
    : super(const UatSettings(apiBaseUrl: AppConfig.apiBaseUrl, themeMode: ThemeMode.system)) {
    _load();
  }

  final Ref _ref;

  Future<void> _load() async {
    final prefs = await SharedPreferences.getInstance();
    final storedUrl = prefs.getString(_apiUrlKey);
    final storedTheme = prefs.getString(_themeKey);

    state = state.copyWith(
      apiBaseUrl: storedUrl ?? AppConfig.apiBaseUrl,
      isOverridden: storedUrl != null,
      themeMode: switch (storedTheme) {
        'light' => ThemeMode.light,
        'dark' => ThemeMode.dark,
        _ => ThemeMode.system,
      },
    );
  }

  Future<void> setApiBaseUrl(String url) async {
    final trimmed = url.trim();
    final prefs = await SharedPreferences.getInstance();

    if (trimmed.isEmpty) {
      await prefs.remove(_apiUrlKey);
      _ref.read(apiClientProvider).updateBaseUrl(AppConfig.apiBaseUrl);
      state = state.copyWith(apiBaseUrl: AppConfig.apiBaseUrl, isOverridden: false);
    } else {
      await prefs.setString(_apiUrlKey, trimmed);
      _ref.read(apiClientProvider).updateBaseUrl(trimmed);
      state = state.copyWith(apiBaseUrl: trimmed, isOverridden: true);
    }

    // Deliberately not invalidating apiClientProvider: rebuilding it would
    // construct a fresh ApiClient from the compile-time default and silently
    // undo the override. Callers invalidate the data providers instead.
  }

  Future<void> setThemeMode(ThemeMode mode) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_themeKey, switch (mode) {
      ThemeMode.light => 'light',
      ThemeMode.dark => 'dark',
      ThemeMode.system => 'system',
    });

    state = state.copyWith(themeMode: mode);
  }
}

final uatSettingsProvider = StateNotifierProvider<UatSettingsNotifier, UatSettings>(
  UatSettingsNotifier.new,
);

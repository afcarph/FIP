import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/config/app_config.dart';
import 'core/theme/app_theme.dart';
import 'router.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Portrait only: every screen is a vertical list or a map, and landscape
  // would cost layout work for no user benefit.
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(statusBarColor: Colors.transparent),
  );

  // Loud in the log rather than fatal: refusing to start would block a
  // developer on an emulator, where the default is correct. An installed build
  // that took it will fail every request, and this line is what points at why.
  if (AppConfig.usingEmulatorDefault) {
    debugPrint(
      'API_BASE_URL was not set at build time, so the app is pointed at '
      '${AppConfig.apiBaseUrl} — the Android emulator alias for the host '
      'machine. On a physical device nothing is there. Rebuild with '
      '--dart-define=API_BASE_URL=<reachable url>.',
    );
  }

  runApp(const ProviderScope(child: FipApp()));
}

class FipApp extends ConsumerWidget {
  const FipApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);

    return MaterialApp.router(
      title: 'Fuel Intelligence Platform',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      darkTheme: AppTheme.dark,
      // Follow the OS preference; a manual override lives in Settings.
      themeMode: ThemeMode.system,
      routerConfig: router,
      builder: (context, child) {
        // Clamp text scaling: beyond 1.4 the dense price tables break, and
        // below 1.0 the app would ignore a user who needs larger text.
        final scale = MediaQuery.textScalerOf(context).scale(1).clamp(1.0, 1.4);

        return MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(scale)),
          child: child!,
        );
      },
    );
  }
}
